/* PicToSVG frontend (VIEW-001, UPLOAD-002). Plain JS, no build step. */
(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };

    // ---- Config ----------------------------------------------------------
    var cfg = {};
    try { cfg = JSON.parse($('pictosvg-config').textContent) || {}; } catch (e) { cfg = {}; }
    var DEF = Object.assign({
        preset: 'engrave', detail: 7, smoothing: 8, noise: 5, smooth_curves: true, width: 150, unit: 'mm'
    }, cfg.defaults || {});
    var MAX_MB = Number(cfg.maxUploadMb) || 20;
    var MAX_BYTES = MAX_MB * 1024 * 1024;
    var AI_ON = !!cfg.aiConfigured;
    var DEBOUNCE_MS = 450;
    var ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    var ALLOWED_EXT = /\.(jpe?g|png|webp)$/i;

    // ---- State -----------------------------------------------------------
    var st = {
        upload: null,          // server upload response
        localUrl: null,        // object URL of the original
        resultUrl: null,       // object URL of the SVG blob
        result: null,          // last convert response
        converted: false,      // at least one successful conversion for this image
        reqId: 0,
        ctrl: null,
        timer: null,
        uploading: false,
        converting: false,
        lastWidth: Number(DEF.width) || 150,
        lastUnit: DEF.unit === 'in' ? 'in' : 'mm'
    };
    var view = { k: 1, x: 0, y: 0 };

    // ---- Small helpers ---------------------------------------------------
    function show(el, on) { el.classList.toggle('d-none', !on); }
    function setText(id, t) { $(id).textContent = t; }
    function fmtBytes(n) {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(2) + ' MB';
    }
    function fmtNum(n, unit) {
        var v = unit === 'in' ? Math.round(n * 1000) / 1000 : Math.round(n * 100) / 100;
        return String(v);
    }
    function unitLabel(u) { return u === 'in' ? 'in' : 'mm'; }
    function showError(id, msg) { var el = $(id); el.textContent = msg; show(el, true); }
    function clearErrors() { show($('upload-error'), false); show($('convert-error'), false); }

    // ---- Setting outputs -------------------------------------------------
    function refreshOutputs() {
        document.querySelectorAll('input[type="range"][data-output]').forEach(function (r) {
            var out = $(r.dataset.output);
            if (!out) return;
            var auto = document.querySelector('[data-auto-for="' + r.id + '"]');
            if (auto && auto.checked) out.textContent = 'Auto';
            else out.textContent = r.value + (r.dataset.suffix || '');
        });
    }
    function syncAuto(cb) {
        var r = $(cb.dataset.autoFor);
        r.disabled = cb.checked;
    }

    function readSettings() {
        function auto(key) {
            return $('adv-' + key + '-auto').checked ? null : Number($('adv-' + key).value);
        }
        var preset = document.querySelector('input[name="preset"]:checked').value;
        var isColor = preset === 'color';
        return {
            preset: preset,
            colors: isColor && $('set-colors').value !== '' ? Number($('set-colors').value) : null,
            detail: Number($('set-detail').value),
            smoothing: Number($('set-smoothing').value),
            noise: Number($('set-noise').value),
            smooth_curves: $('opt-smooth').checked,
            threshold: auto('threshold'),
            contrast: Number($('adv-contrast').value),
            source: traceSource(),
            invert: $('adv-invert').checked,
            tolerance: auto('tolerance'),
            speck: auto('speck'),
            corners: auto('corners'),
            outline_only: $('adv-outline-only').checked,
            transparent_background: isColor ? $('color-remove-bg').checked : $('adv-transparent').checked
        };
    }

    function syncColorOptions() {
        var on = document.querySelector('input[name="preset"]:checked').value === 'color';
        show($('color-options'), on);
    }

    function resetSettings() {
        var p = document.getElementById('preset-' + DEF.preset) || $('preset-engrave');
        p.checked = true;
        $('set-detail').value = DEF.detail;
        $('set-smoothing').value = DEF.smoothing;
        $('set-noise').value = DEF.noise;
        $('opt-smooth').checked = !!DEF.smooth_curves;
        document.querySelectorAll('[data-auto-for]').forEach(function (cb) {
            cb.checked = true;
            var r = $(cb.dataset.autoFor);
            r.value = r.defaultValue;
            syncAuto(cb);
        });
        $('adv-contrast').value = 0;
        $('adv-invert').checked = false;
        $('adv-outline-only').checked = DEF.preset === 'outline';
        $('adv-transparent').checked = true;
        $('set-colors').value = '';
        $('color-remove-bg').checked = false;
        syncColorOptions();
        refreshOutputs();
    }

    // ---- Errors ----------------------------------------------------------
    function messageFor(status, body) {
        body = body || {};
        if (status === 413 || body.code === 'image_too_large') return 'Image is larger than ' + MAX_MB + ' MB';
        if (status === 404 || body.code === 'expired') return 'This image has expired — please upload it again';
        if (status === 429) return 'Too many requests — wait a moment';
        if (status === 422 && body.errors) {
            var k = Object.keys(body.errors)[0];
            if (k && body.errors[k] && body.errors[k][0]) return body.errors[k][0];
        }
        if (body.message) return body.message;
        return 'Something went wrong (error ' + status + '). Please try again.';
    }
    function isGone(status, body) {
        return status === 404 || (body && body.code === 'expired') || (body && body.code === 'invalid_token');
    }

    // ---- Upload ----------------------------------------------------------
    function validateFile(file) {
        var okType = ALLOWED_MIME.indexOf(file.type) !== -1 || (!file.type && ALLOWED_EXT.test(file.name));
        if (!okType) return 'Please choose a JPG, PNG or WebP image.';
        if (file.size > MAX_BYTES) return 'Image is larger than ' + MAX_MB + ' MB';
        if (file.size === 0) return 'That file is empty.';
        return null;
    }

    function handleFile(file) {
        if (!file || st.uploading) return;
        clearErrors();
        var err = validateFile(file);
        if (err) { showError('upload-error', err); return; }
        resetImage(true);
        var url = URL.createObjectURL(file);
        st.localUrl = url;
        upload(file, url);
    }

    function upload(file, localUrl) {
        st.uploading = true;
        show($('dropzone'), false);
        show($('upload-progress'), true);
        setProgress(0);
        updateButtons();

        var xhr = new XMLHttpRequest();
        var fd = new FormData();
        fd.append('image', file);
        xhr.open('POST', '/api/images/upload');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) setProgress(Math.round(e.loaded / e.total * 100));
        };
        xhr.onerror = function () { uploadFailed('Network problem while uploading. Check your connection and try again.'); };
        xhr.onabort = function () { uploadFailed(null); };
        xhr.onload = function () {
            var body = null;
            try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
            if (xhr.status >= 200 && xhr.status < 300 && body && body.id && body.token) {
                if (st.localUrl !== localUrl) return; // superseded
                st.uploading = false;
                st.upload = body;
                show($('upload-progress'), false);
                showUploaded(file);
                updateButtons();
            } else {
                uploadFailed(messageFor(xhr.status, body));
            }
        };
        xhr.send(fd);
    }

    function setProgress(p) {
        setText('upload-pct', p + '%');
        $('upload-bar').style.width = p + '%';
        $('upload-bar').parentNode.setAttribute('aria-valuenow', String(p));
    }

    function uploadFailed(msg) {
        st.uploading = false;
        show($('upload-progress'), false);
        show($('dropzone'), true);
        if (st.localUrl) { URL.revokeObjectURL(st.localUrl); st.localUrl = null; }
        if (msg) showError('upload-error', msg);
        updateButtons();
    }

    function showUploaded(file) {
        var u = st.upload;
        $('upload-thumb').src = st.localUrl;
        setText('upload-name', u.name || file.name);
        var dims = (u.width && u.height) ? (u.width + ' × ' + u.height + ' px') : 'size unknown';
        setText('upload-details', dims + ' · ' + fmtBytes(u.bytes || file.size));
        show($('upload-info'), true);
        var img = $('img-original');
        img.src = st.localUrl;
        show(img, true);
        show($('empty-original'), false);
    }

    // Clear everything about the current image/result. keepErrors: leave error alerts alone.
    function resetImage(keepErrors) {
        cancelPending();
        st.reqId++;
        st.converted = false;
        st.result = null;
        st.upload = null;
        if (st.localUrl) { URL.revokeObjectURL(st.localUrl); st.localUrl = null; }
        clearResult();
        $('img-original').removeAttribute('src');
        show($('img-original'), false);
        show($('empty-original'), true);
        $('upload-thumb').removeAttribute('src');
        show($('upload-info'), false);
        clearRedraw();
        show($('upload-progress'), false);
        show($('dropzone'), true);
        $('file-input').value = '';
        if (!keepErrors) clearErrors();
        setConverting(false);
        updateButtons();
    }

    function clearResult() {
        if (st.resultUrl) { URL.revokeObjectURL(st.resultUrl); st.resultUrl = null; }
        st.result = null;
        var img = $('img-result');
        img.removeAttribute('src');
        show(img, false);
        show($('empty-svg'), true);
        $('warnings').textContent = '';
        ['stat-paths', 'stat-nodes', 'stat-size', 'stat-bytes'].forEach(function (id) { setText(id, '-'); });
        $('height-input').value = '-';
        view.k = 1; view.x = 0; view.y = 0; applyView();
        updateDownload();
    }

    // ---- Conversion ------------------------------------------------------
    function cancelPending() {
        if (st.timer) { clearTimeout(st.timer); st.timer = null; }
        if (st.ctrl) { st.ctrl.abort(); st.ctrl = null; }
    }

    function scheduleConvert() {
        if (!st.converted || !st.upload) return;
        if (st.timer) clearTimeout(st.timer);
        st.timer = setTimeout(function () { st.timer = null; convert(false); }, DEBOUNCE_MS);
    }

    function currentWidth() {
        var w = parseFloat($('width-input').value);
        return (isFinite(w) && w > 0) ? w : st.lastWidth;
    }

    function setConverting(on) {
        st.converting = on;
        show($('svg-loading'), on);
        show($('convert-spinner'), on);
        setText('convert-label', on ? 'Converting…' : 'Convert to SVG');
        updateButtons();
    }

    function convert(isFirstClick) {
        if (!st.upload) return;
        if (st.timer) { clearTimeout(st.timer); st.timer = null; }
        if (st.ctrl) st.ctrl.abort();
        var ctrl = new AbortController();
        st.ctrl = ctrl;
        var myId = ++st.reqId;
        show($('convert-error'), false);
        setConverting(true);

        var body = Object.assign({
            id: st.upload.id,
            token: st.upload.token,
            width: currentWidth(),
            unit: $('unit-select').value
        }, readSettings());

        fetch('/api/svg/convert', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body),
            signal: ctrl.signal
        }).then(function (res) {
            return res.text().then(function (t) {
                var data = null;
                try { data = JSON.parse(t); } catch (e) { data = null; }
                return { status: res.status, ok: res.ok, data: data };
            });
        }).then(function (r) {
            if (myId !== st.reqId) return; // stale
            st.ctrl = null;
            if (r.ok && r.data && typeof r.data.svg === 'string') {
                applyResult(r.data);
                var first = !st.converted;
                st.converted = true;
                setConverting(false);
                if (first) scrollToPreview();
            } else {
                setConverting(false);
                if (isGone(r.status, r.data)) {
                    resetImage(true);
                    showError('upload-error', messageFor(r.status === 403 ? 404 : r.status, r.data));
                } else {
                    showError('convert-error', messageFor(r.status, r.data));
                }
            }
        }).catch(function (e) {
            if (myId !== st.reqId) return; // aborted or stale
            st.ctrl = null;
            setConverting(false);
            if (e && e.name === 'AbortError') return;
            showError('convert-error', 'Network problem — could not reach the server. Check your connection and try again.');
        });
    }

    function scrollToPreview() {
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        $('step-preview').scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    }

    function applyResult(data) {
        st.result = data;
        // Render only through an <img> backed by a blob URL; never inject the markup.
        var blob = new Blob([data.svg], { type: 'image/svg+xml' });
        var url = URL.createObjectURL(blob);
        var old = st.resultUrl;
        st.resultUrl = url;
        var img = $('img-result');
        img.src = url;
        show(img, true);
        show($('empty-svg'), false);
        if (old) setTimeout(function () { URL.revokeObjectURL(old); }, 0);

        var s = data.stats || {};
        setText('stat-paths', (s.paths != null ? String(s.paths) : '-') + (s.colors && s.colors.length ? ' in ' + s.colors.length + (s.colors.length === 1 ? ' color' : ' colors') : ''));
        if (s.nodes != null) {
            var txt = String(s.nodes);
            if (s.nodes_before != null && Number(s.nodes_before) > Number(s.nodes)) {
                txt += ' (was ' + s.nodes_before + ' before optimization)';
            }
            setText('stat-nodes', txt);
        } else setText('stat-nodes', '-');
        setText('stat-bytes', s.bytes != null ? fmtBytes(s.bytes) : fmtBytes(new Blob([data.svg]).size));
        renderWarnings(data.warnings);
        updateSize();
    }

    function renderWarnings(list) {
        var box = $('warnings');
        box.textContent = '';
        (Array.isArray(list) ? list : []).forEach(function (w) {
            var div = document.createElement('div');
            div.className = 'alert alert-warning alert-dismissible fade show py-2 mb-2';
            div.setAttribute('role', 'alert');
            var span = document.createElement('span');
            span.textContent = String(w);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-close';
            btn.setAttribute('data-bs-dismiss', 'alert');
            btn.setAttribute('aria-label', 'Dismiss warning');
            div.appendChild(span);
            div.appendChild(btn);
            box.appendChild(div);
        });
    }

    // ---- Output size / download -----------------------------------------
    function aspect() {
        var s = st.result && st.result.stats;
        if (s && Number(s.pixel_width) > 0 && Number(s.pixel_height) > 0) return Number(s.pixel_height) / Number(s.pixel_width);
        if (st.upload && st.upload.width > 0 && st.upload.height > 0) return st.upload.height / st.upload.width;
        return null;
    }

    function updateSize() {
        var wInput = $('width-input');
        var w = parseFloat(wInput.value);
        var valid = isFinite(w) && w > 0;
        wInput.classList.toggle('is-invalid', !valid);
        if (valid) st.lastWidth = w;
        var unit = $('unit-select').value;
        var a = aspect();
        if (st.result && valid && a) {
            var h = w * a;
            $('height-input').value = fmtNum(h, unit) + ' ' + unitLabel(unit);
            setText('stat-size', fmtNum(w, unit) + ' × ' + fmtNum(h, unit) + ' ' + unitLabel(unit));
        } else if (!st.result) {
            $('height-input').value = '-';
            setText('stat-size', '-');
        }
        updateDownload(valid);
    }

    function updateDownload(valid) {
        var a = $('btn-download');
        var w = parseFloat($('width-input').value);
        if (valid === undefined) valid = isFinite(w) && w > 0;
        var ready = !!(st.result && st.upload && valid);
        a.classList.toggle('disabled', !ready);
        a.setAttribute('aria-disabled', ready ? 'false' : 'true');
        a.tabIndex = ready ? 0 : -1;
        if (ready) {
            var p = new URLSearchParams({
                token: st.upload.token,
                version: st.result.version,
                width: String(w),
                unit: $('unit-select').value
            });
            a.href = '/api/svg/download/' + encodeURIComponent(st.upload.id) + '?' + p.toString();
        } else {
            a.setAttribute('href', '#');
        }
    }

    function onUnitChange() {
        var to = $('unit-select').value;
        var from = st.lastUnit;
        if (to !== from) {
            var w = parseFloat($('width-input').value);
            if (!isFinite(w) || w <= 0) w = st.lastWidth;
            var conv = to === 'in' ? w / 25.4 : w * 25.4;
            conv = to === 'in' ? Math.round(conv * 1000) / 1000 : Math.round(conv * 100) / 100;
            $('width-input').value = String(conv);
            st.lastUnit = to;
        }
        updateSize();
    }

    // ---- Buttons ---------------------------------------------------------
    function updateButtons() {
        $('btn-convert').disabled = !st.upload || st.converting || st.uploading;
        $('btn-ai').disabled = !AI_ON || !st.upload || !!st.aiBusy;
    }

    // ---- Zoom / pan ------------------------------------------------------
    var stages = [];
    var viewports = [];

    function applyView() {
        var t = 'translate(' + view.x + 'px,' + view.y + 'px) scale(' + view.k + ')';
        stages.forEach(function (s) { s.style.transform = t; });
        setText('zoom-level', Math.round(view.k * 100) + '%');
    }
    function zoomAt(px, py, factor) {
        var nk = Math.min(32, Math.max(0.5, view.k * factor));
        var f = nk / view.k;
        view.x = px - (px - view.x) * f;
        view.y = py - (py - view.y) * f;
        view.k = nk;
        applyView();
    }
    function fit() { view.k = 1; view.x = 0; view.y = 0; applyView(); }
    function activeViewport() {
        return $('preview-grid').classList.contains('svg-only') ? $('vp-svg') : $('vp-original');
    }
    function zoomCenter(factor) {
        var r = activeViewport().getBoundingClientRect();
        zoomAt(r.width / 2, r.height / 2, factor);
    }

    function setupViewport(vp) {
        var ptrs = new Map();
        vp.addEventListener('wheel', function (e) {
            e.preventDefault();
            var r = vp.getBoundingClientRect();
            zoomAt(e.clientX - r.left, e.clientY - r.top, Math.exp(-e.deltaY * 0.0015));
        }, { passive: false });
        vp.addEventListener('pointerdown', function (e) {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            ptrs.set(e.pointerId, { x: e.clientX, y: e.clientY });
            try { vp.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
            vp.classList.add('panning');
        });
        vp.addEventListener('pointermove', function (e) {
            var prev = ptrs.get(e.pointerId);
            if (!prev) return;
            var cur = { x: e.clientX, y: e.clientY };
            if (ptrs.size === 1) {
                view.x += cur.x - prev.x;
                view.y += cur.y - prev.y;
                applyView();
            } else if (ptrs.size === 2) {
                var other = null;
                ptrs.forEach(function (v, id) { if (id !== e.pointerId) other = v; });
                var d0 = Math.hypot(prev.x - other.x, prev.y - other.y) || 1;
                var d1 = Math.hypot(cur.x - other.x, cur.y - other.y) || 1;
                var r = vp.getBoundingClientRect();
                view.x += (cur.x - prev.x) / 2;
                view.y += (cur.y - prev.y) / 2;
                zoomAt((cur.x + other.x) / 2 - r.left, (cur.y + other.y) / 2 - r.top, d1 / d0);
            }
            ptrs.set(e.pointerId, cur);
        });
        function end(e) {
            ptrs.delete(e.pointerId);
            if (!ptrs.size) vp.classList.remove('panning');
        }
        vp.addEventListener('pointerup', end);
        vp.addEventListener('pointercancel', end);
        vp.addEventListener('dblclick', fit);
        vp.addEventListener('keydown', function (e) {
            var r = vp.getBoundingClientRect();
            if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomAt(r.width / 2, r.height / 2, 1.25); }
            else if (e.key === '-' || e.key === '_') { e.preventDefault(); zoomAt(r.width / 2, r.height / 2, 0.8); }
            else if (e.key === '0') { e.preventDefault(); fit(); }
            else if (e.key.indexOf('Arrow') === 0) {
                e.preventDefault();
                var step = 40;
                if (e.key === 'ArrowLeft') view.x += step;
                if (e.key === 'ArrowRight') view.x -= step;
                if (e.key === 'ArrowUp') view.y += step;
                if (e.key === 'ArrowDown') view.y -= step;
                applyView();
            }
        });
    }

    // ---- AI Redraw (optional, never blocks conversion) -------------------
    // Original -> AI redraw -> normal trace. The redraw is kept beside the
    // original; the user picks which one is traced.
    function traceSource() {
        var r = document.querySelector('input[name="trace-source"]:checked');
        return st.redrawUrl && r && r.value === 'redraw' ? 'redraw' : 'original';
    }
    function showSourceImage() {
        var useRedraw = traceSource() === 'redraw';
        var img = $('img-original');
        if (!st.localUrl) return;
        img.src = useRedraw ? st.redrawUrl : st.localUrl;
        img.alt = useRedraw ? 'AI redrawn picture' : 'Original uploaded image';
        setText('h-original', useRedraw ? 'AI Redrawn Picture' : 'Original Image');
    }
    function clearRedraw() {
        if (st.redrawUrl) { URL.revokeObjectURL(st.redrawUrl); st.redrawUrl = null; }
        show($('ai-source'), false);
        $('source-redraw').checked = true;
        setText('h-original', 'Original Image');
    }
    function setAiBusy(on) {
        st.aiBusy = on;
        show($('ai-spinner'), on);
        setText('ai-label', on ? 'Redrawing… this can take a minute or two' : (st.redrawUrl ? 'Redraw again with AI' : 'Redraw with AI'));
        updateButtons();
    }
    function aiRedraw() {
        if (!AI_ON || !st.upload || st.aiBusy) return;
        var up = st.upload;
        show($('convert-error'), false);
        setAiBusy(true);
        fetch('/api/ai/redraw', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ id: up.id, token: up.token })
        }).then(function (res) {
            return res.json().catch(function () { return null; }).then(function (b) {
                return { ok: res.ok, status: res.status, body: b };
            });
        }).then(function (r) {
            if (!r.ok) {
                var err = new Error(messageFor(r.status, r.body));
                err.gone = isGone(r.status, r.body);
                throw err;
            }
            return fetch('/api/ai/redraw/' + encodeURIComponent(up.id) + '?' + new URLSearchParams({ token: up.token, v: r.body.version }), { headers: { 'Accept': 'image/png' } });
        }).then(function (res) {
            if (!res.ok) throw new Error('The redrawn picture could not be loaded. Please try again.');
            return res.blob();
        }).then(function (blob) {
            if (st.upload !== up) { setAiBusy(false); return; } // image changed meanwhile
            if (st.redrawUrl) URL.revokeObjectURL(st.redrawUrl);
            st.redrawUrl = URL.createObjectURL(blob);
            $('source-redraw').checked = true;
            show($('ai-source'), true);
            setAiBusy(false);
            showSourceImage();
            convert(true);
        }).catch(function (e) {
            setAiBusy(false);
            if (st.upload !== up) return;
            if (e && e.gone) { resetImage(true); showError('upload-error', e.message); return; }
            showError('convert-error', (e && e.message && e.name !== 'TypeError') ? e.message : 'Network problem — could not reach the server.');
        });
    }

    // ---- Wire up ---------------------------------------------------------
    function init() {
        stages = Array.prototype.slice.call(document.querySelectorAll('.stage'));
        viewports = [$('vp-original'), $('vp-svg')];
        viewports.forEach(setupViewport);

        // Dropzone
        var dz = $('dropzone'), fi = $('file-input');
        dz.addEventListener('click', function () { fi.click(); });
        dz.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fi.click(); }
        });
        fi.addEventListener('change', function () { if (fi.files && fi.files[0]) handleFile(fi.files[0]); });
        ['dragenter', 'dragover'].forEach(function (n) {
            dz.addEventListener(n, function (e) { e.preventDefault(); dz.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (n) {
            dz.addEventListener(n, function (e) { e.preventDefault(); dz.classList.remove('dragover'); });
        });
        dz.addEventListener('drop', function (e) {
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (f) handleFile(f);
        });
        // Stop the browser navigating to a file dropped outside the zone.
        window.addEventListener('dragover', function (e) { e.preventDefault(); });
        window.addEventListener('drop', function (e) { e.preventDefault(); });
        document.addEventListener('paste', function (e) {
            var items = (e.clipboardData && e.clipboardData.files) || [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image/') === 0) { e.preventDefault(); handleFile(items[i]); return; }
            }
        });
        $('btn-change').addEventListener('click', function () { fi.click(); });
        $('btn-remove').addEventListener('click', function () { resetImage(false); });

        // Settings
        document.querySelectorAll('input[type="range"][data-output]').forEach(function (r) {
            r.addEventListener('input', function () { refreshOutputs(); scheduleConvert(); });
        });
        document.querySelectorAll('[data-auto-for]').forEach(function (cb) {
            syncAuto(cb);
            cb.addEventListener('change', function () { syncAuto(cb); refreshOutputs(); scheduleConvert(); });
        });
        ['opt-smooth', 'adv-invert', 'adv-outline-only', 'adv-transparent'].forEach(function (id) {
            $(id).addEventListener('change', scheduleConvert);
        });
        document.querySelectorAll('input[name="preset"]').forEach(function (r) {
            r.addEventListener('change', function () {
                // Outline Cut implies outline-only output; other presets clear it.
                $('adv-outline-only').checked = r.value === 'outline';
                syncColorOptions();
                scheduleConvert();
            });
        });
        $('adv-outline-only').checked = DEF.preset === 'outline';
        ['set-colors', 'color-remove-bg'].forEach(function (id) { $(id).addEventListener('change', scheduleConvert); });
        syncColorOptions();
        $('btn-reset').addEventListener('click', function () { resetSettings(); scheduleConvert(); });
        $('btn-convert').addEventListener('click', function () { convert(true); });
        $('btn-ai').addEventListener('click', aiRedraw);
        document.querySelectorAll('input[name="trace-source"]').forEach(function (r) {
            r.addEventListener('change', function () { showSourceImage(); convert(false); });
        });

        // Output size (never re-traces)
        $('width-input').value = String(st.lastWidth);
        $('unit-select').value = st.lastUnit;
        $('width-input').addEventListener('input', updateSize);
        $('unit-select').addEventListener('change', onUnitChange);
        $('btn-download').addEventListener('click', function (e) {
            if ($('btn-download').getAttribute('aria-disabled') === 'true') e.preventDefault();
        });

        // Preview toolbar
        $('zoom-in').addEventListener('click', function () { zoomCenter(1.25); });
        $('zoom-out').addEventListener('click', function () { zoomCenter(0.8); });
        $('zoom-fit').addEventListener('click', fit);
        document.querySelectorAll('input[name="view-mode"]').forEach(function (r) {
            r.addEventListener('change', function () {
                $('preview-grid').classList.toggle('svg-only', r.value === 'svg' && r.checked);
                fit();
            });
        });
        document.querySelectorAll('input[name="bg-mode"]').forEach(function (r) {
            r.addEventListener('change', function () {
                var vp = $('vp-svg');
                vp.classList.toggle('bg-white', r.value === 'white' && r.checked);
                vp.classList.toggle('bg-checker', r.value === 'checker' && r.checked);
            });
        });

        refreshOutputs();
        applyView();
        updateButtons();
        updateDownload();
    }

    init();
})();
