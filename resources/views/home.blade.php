@extends('layouts.app')

@php
    $cfg = array_replace_recursive([
        'maxUploadMb' => 20,
        'aiConfigured' => false,
        'defaults' => [
            'preset' => 'engrave', 'detail' => 7, 'smoothing' => 8, 'noise' => 5,
            'smooth_curves' => true, 'width' => 150, 'unit' => 'mm',
        ],
    ], isset($config) && is_array($config) ? $config : []);
    $d = $cfg['defaults'];

    $presets = [
        'engrave' => ['Engrave', 'Clean black-and-white artwork with filled regions'],
        'outline' => ['Outline Cut', 'Closed contours for laser/CNC cutting'],
        'lineart' => ['Line Art', 'Traces black line artwork; lines are traced as outlined shapes, not single-stroke centerlines'],
        'color' => ['Color', 'Keeps the picture’s solid colors as separate colored shapes'],
    ];

    // Sliders shown by default.
    $sliders = [
        ['detail', 'Detail', 1, 10, 'Higher keeps more fine detail; lower gives simpler shapes.'],
        ['smoothing', 'Smoothing', 0, 10, 'Higher gives rounder, cleaner curves.'],
        ['noise', 'Noise Removal', 0, 10, 'Higher removes more tiny specks and stray dots.'],
    ];

    // Advanced sliders that can be left on Auto: key, label, min, max, step, shown value when Auto is cleared, hint.
    $autoRows = [
        ['threshold', 'Black/white threshold', 1, 99, 1, 50, 'Brightness level that separates black from white.'],
        ['tolerance', 'Curve simplification tolerance', 0, 5, 0.1, 1, 'Higher uses fewer points but may soften small details.'],
        ['speck', 'Minimum speck size', 0, 1000, 1, 20, 'Spots smaller than this (in source pixels) are removed.'],
        ['corners', 'Corner preservation', 0, 10, 1, 5, 'Higher keeps sharp corners sharp.'],
    ];
@endphp

@section('content')
<script type="application/json" id="pictosvg-config">@json($cfg)</script>

<a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>

<header class="app-header">
    <div class="container-xl d-flex align-items-center gap-3">
        <svg class="brand-mark" viewBox="0 0 32 32" width="40" height="40" aria-hidden="true">
            <rect width="32" height="32" rx="7" fill="#1f2227"/>
            <path d="M7 23 L13 9 Q16 4 19 9 L25 23 Z" fill="none" stroke="#e5383b" stroke-width="2.5" stroke-linejoin="round"/>
            <circle cx="7" cy="23" r="2.2" fill="#fff"/><circle cx="25" cy="23" r="2.2" fill="#fff"/>
        </svg>
        <div>
            <h1 class="brand-name">PicToSVG</h1>
            <p class="brand-tagline">Turn Pictures into Precision Vectors</p>
        </div>
    </div>
</header>

<main id="main" class="container-xl pb-5">

    {{-- 1. Upload --}}
    <section class="panel step" id="step-upload" aria-labelledby="h-upload">
        <h2 class="step-title" id="h-upload"><span class="step-num">1</span> Upload Image</h2>

        <div id="upload-error" class="alert alert-danger d-none" role="alert"></div>

        <div id="dropzone" class="dropzone" role="button" tabindex="0"
             aria-label="Upload an image: drop a file here, press Enter to browse, or paste from the clipboard">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>
            <p class="dz-main mb-1">Drag and drop an image here, or <span class="dz-link">browse</span></p>
            <p class="dz-sub mb-0">JPG, PNG or WebP, up to {{ (int) $cfg['maxUploadMb'] }} MB. You can also paste from the clipboard.</p>
            <input type="file" id="file-input" class="d-none" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" aria-label="Choose an image file">
        </div>

        <div id="upload-progress" class="d-none" aria-live="polite">
            <div class="d-flex justify-content-between small mb-1"><span>Uploading…</span><span id="upload-pct">0%</span></div>
            <div class="progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div class="progress-bar" id="upload-bar" style="width:0%"></div>
            </div>
        </div>

        <div id="upload-info" class="upload-info d-none">
            <img id="upload-thumb" class="upload-thumb" alt="Thumbnail of the uploaded image">
            <div class="upload-meta">
                <div class="fw-semibold text-break" id="upload-name"></div>
                <div class="text-secondary small" id="upload-details"></div>
            </div>
            <div class="ms-md-auto d-flex gap-2">
                <button type="button" class="btn btn-outline-light btn-sm" id="btn-change">Change</button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="btn-remove">Remove</button>
            </div>
        </div>
    </section>

    {{-- 2. Convert --}}
    <section class="panel step" id="step-convert" aria-labelledby="h-convert">
        <h2 class="step-title" id="h-convert"><span class="step-num">2</span> Convert to SVG</h2>

        <div id="convert-error" class="alert alert-danger d-none" role="alert"></div>

        <fieldset class="mb-3">
            <legend class="field-legend">Preset</legend>
            <div class="preset-grid">
                @foreach ($presets as $key => [$label, $desc])
                    <div>
                        <input class="preset-input" type="radio" name="preset" id="preset-{{ $key }}" value="{{ $key }}" @checked(($d['preset'] ?? 'engrave') === $key)>
                        <label class="preset-card" for="preset-{{ $key }}">
                            <span class="preset-name">{{ $label }}</span>
                            <span class="preset-desc">{{ $desc }}</span>
                        </label>
                    </div>
                @endforeach
            </div>
        </fieldset>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="opt-smooth" @checked(!empty($d['smooth_curves']))>
            <label class="form-check-label" for="opt-smooth">Optimize Smooth Curves</label>
        </div>

        <div class="row g-4 mb-3">
            @foreach ($sliders as [$key, $label, $min, $max, $hint])
                <div class="col-md-4">
                    <div class="d-flex justify-content-between">
                        <label for="set-{{ $key }}" class="form-label mb-1">{{ $label }}</label>
                        <output for="set-{{ $key }}" id="out-{{ $key }}" class="value-badge"></output>
                    </div>
                    <input type="range" class="form-range" id="set-{{ $key }}" min="{{ $min }}" max="{{ $max }}" step="1" value="{{ $d[$key] ?? 5 }}" data-output="out-{{ $key }}" data-suffix="/10" aria-describedby="hint-{{ $key }}">
                    <div class="form-text" id="hint-{{ $key }}">{{ $hint }}</div>
                </div>
            @endforeach
        </div>

        <div class="color-options d-none mb-3" id="color-options">
            <div class="row g-3 align-items-end">
                <div class="col-sm-5 col-md-4">
                    <label for="set-colors" class="form-label mb-1">Number of colors</label>
                    <select class="form-select" id="set-colors" aria-describedby="hint-colors">
                        <option value="" selected>Automatic</option>
                        @foreach (range(2, 16) as $n)
                            <option value="{{ $n }}">Up to {{ $n }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-7 col-md-8">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="color-remove-bg">
                        <label class="form-check-label" for="color-remove-bg">Leave out the background color</label>
                    </div>
                </div>
            </div>
            <div class="form-text" id="hint-colors">Best for logos, clip art and drawings with flat colors. Each color becomes its own group of shapes in the SVG, in the same color as the picture. Photos and gradients convert only approximately.</div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <button type="button" class="btn btn-accent btn-lg px-4" id="btn-convert" disabled>
                <span class="spinner-border spinner-border-sm d-none me-2" id="convert-spinner" aria-hidden="true"></span>
                <span id="convert-label">Convert to SVG</span>
            </button>
            <button type="button" class="btn btn-outline-light" id="btn-reset">Reset Settings</button>
            <button class="btn btn-outline-secondary ms-md-auto" type="button" data-bs-toggle="collapse" data-bs-target="#advanced" aria-expanded="false" aria-controls="advanced" id="btn-advanced">Advanced Settings</button>
        </div>

        <div class="collapse" id="advanced">
            <div class="advanced-box">
                <div class="row g-4">
                    @foreach ($autoRows as [$key, $label, $min, $max, $step, $shown, $hint])
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center">
                                <label for="adv-{{ $key }}" class="form-label mb-1">{{ $label }}</label>
                                <span class="d-flex align-items-center gap-2">
                                    <output for="adv-{{ $key }}" id="out-adv-{{ $key }}" class="value-badge"></output>
                                    <span class="form-check form-check-inline m-0">
                                        <input class="form-check-input" type="checkbox" id="adv-{{ $key }}-auto" checked data-auto-for="adv-{{ $key }}">
                                        <label class="form-check-label small" for="adv-{{ $key }}-auto">Auto</label>
                                    </span>
                                </span>
                            </div>
                            <input type="range" class="form-range" id="adv-{{ $key }}" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" value="{{ $shown }}" data-output="out-adv-{{ $key }}" aria-describedby="hint-adv-{{ $key }}" disabled>
                            <div class="form-text" id="hint-adv-{{ $key }}">{{ $hint }}</div>
                        </div>
                    @endforeach
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between">
                            <label for="adv-contrast" class="form-label mb-1">Image contrast</label>
                            <output for="adv-contrast" id="out-adv-contrast" class="value-badge"></output>
                        </div>
                        <input type="range" class="form-range" id="adv-contrast" min="-50" max="50" step="1" value="0" data-output="out-adv-contrast" aria-describedby="hint-adv-contrast">
                        <div class="form-text" id="hint-adv-contrast">Boost or soften contrast before converting.</div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="adv-invert">
                            <label class="form-check-label" for="adv-invert">Invert black/white</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="adv-outline-only">
                            <label class="form-check-label" for="adv-outline-only">Outline-only output</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="adv-transparent" checked>
                            <label class="form-check-label" for="adv-transparent">Transparent background</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ai-row mt-3">
            <button type="button" class="btn btn-outline-secondary" id="btn-ai" @disabled(empty($cfg['aiConfigured']))>
                <span class="spinner-border spinner-border-sm me-1 d-none" id="ai-spinner" aria-hidden="true"></span><span id="ai-label">Redraw with AI</span>
            </button>
            <span class="small text-secondary" id="ai-note">
                @if (empty($cfg['aiConfigured']))
                    AI Redraw is not set up on this server. Standard conversion works without it.
                @else
                    Optional, for photos and busy pictures. Sends your picture to OpenAI, which redraws it as clean black-and-white artwork (can take a minute or two). AI may reinterpret, omit or alter details.
                @endif
            </span>
        </div>
        <div class="d-none mt-3" id="ai-source" role="group" aria-labelledby="ai-source-label">
            <div class="small text-secondary mb-1" id="ai-source-label">Picture to trace</div>
            <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="trace-source" id="source-original" value="original" autocomplete="off">
                <label class="btn btn-sm btn-outline-light" for="source-original">Original picture</label>
                <input type="radio" class="btn-check" name="trace-source" id="source-redraw" value="redraw" autocomplete="off" checked>
                <label class="btn btn-sm btn-outline-light" for="source-redraw">AI redrawn picture</label>
            </div>
            <div class="small text-secondary mt-1">Switch between them to compare. Your original is never changed.</div>
        </div>
    </section>

    {{-- 3. Preview --}}
    <section class="panel step" id="step-preview" aria-labelledby="h-preview">
        <h2 class="step-title" id="h-preview"><span class="step-num">3</span> Preview and Download</h2>

        <div id="warnings" class="mb-3" aria-live="polite"></div>

        <div class="toolbar mb-3" role="toolbar" aria-label="Preview controls">
            <div class="btn-group" role="group" aria-label="Zoom">
                <button type="button" class="btn btn-outline-light btn-sm" id="zoom-out" aria-label="Zoom out" title="Zoom out (-)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M8 11h6M20 20l-4-4"/></svg></button>
                <button type="button" class="btn btn-outline-light btn-sm" id="zoom-in" aria-label="Zoom in" title="Zoom in (+)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M8 11h6M11 8v6M20 20l-4-4"/></svg></button>
                <button type="button" class="btn btn-outline-light btn-sm" id="zoom-fit" title="Fit to panel (0)">Fit <span id="zoom-level" class="text-secondary ms-1">100%</span></button>
            </div>
            <div class="btn-group" role="group" aria-label="View mode">
                <input type="radio" class="btn-check" name="view-mode" id="view-side" value="side" checked>
                <label class="btn btn-outline-light btn-sm" for="view-side">Side by side</label>
                <input type="radio" class="btn-check" name="view-mode" id="view-svg" value="svg">
                <label class="btn btn-outline-light btn-sm" for="view-svg">SVG only</label>
            </div>
            <div class="btn-group" role="group" aria-label="SVG panel background">
                <input type="radio" class="btn-check" name="bg-mode" id="bg-checker" value="checker" checked>
                <label class="btn btn-outline-light btn-sm" for="bg-checker">Transparent</label>
                <input type="radio" class="btn-check" name="bg-mode" id="bg-white" value="white">
                <label class="btn btn-outline-light btn-sm" for="bg-white">White</label>
            </div>
            <span class="small text-secondary ms-md-auto d-none d-md-inline">Scroll to zoom, drag to pan</span>
        </div>

        <div class="preview-grid" id="preview-grid">
            <div class="preview-col" id="col-original">
                <h3 class="preview-title" id="h-original">Original Image</h3>
                <div class="viewport" id="vp-original" tabindex="0" role="group" aria-labelledby="h-original" aria-describedby="vp-help">
                    <div class="stage"><img id="img-original" class="d-none" alt="Original uploaded image" draggable="false"></div>
                    <div class="vp-empty" id="empty-original">Upload an image to see it here</div>
                </div>
            </div>
            <div class="preview-col" id="col-svg">
                <h3 class="preview-title" id="h-svg">SVG Result</h3>
                <div class="viewport bg-checker" id="vp-svg" tabindex="0" role="group" aria-labelledby="h-svg" aria-describedby="vp-help">
                    <div class="stage"><img id="img-result" class="d-none" alt="Converted SVG result" draggable="false"></div>
                    <div class="vp-empty" id="empty-svg">Your SVG will appear here</div>
                    <div class="vp-loading d-none" id="svg-loading" role="status">
                        <div class="spinner-border text-light" aria-hidden="true"></div>
                        <span class="mt-2">Converting…</span>
                    </div>
                </div>
            </div>
        </div>
        <p class="visually-hidden" id="vp-help">Focus a preview and use plus and minus to zoom, zero to fit, or drag to pan.</p>

        <dl class="stats" id="stats" aria-label="Result statistics">
            <div><dt>Paths</dt><dd id="stat-paths">-</dd></div>
            <div><dt>Nodes</dt><dd id="stat-nodes">-</dd></div>
            <div><dt>Output size</dt><dd id="stat-size">-</dd></div>
            <div><dt>File size</dt><dd id="stat-bytes">-</dd></div>
        </dl>

        <div class="row g-3 align-items-end mt-1">
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label" for="width-input">Output width</label>
                <input type="number" class="form-control" id="width-input" min="0.01" step="any" value="{{ $d['width'] ?? 150 }}" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label" for="unit-select">Unit</label>
                <select class="form-select" id="unit-select">
                    <option value="mm" @selected(($d['unit'] ?? 'mm') === 'mm')>Millimeters</option>
                    <option value="in" @selected(($d['unit'] ?? 'mm') === 'in')>Inches</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label" for="height-input">Output height</label>
                <input type="text" class="form-control" id="height-input" readonly value="-" aria-describedby="height-help">
            </div>
            <div class="col-12 col-md-3 col-lg-6 d-flex justify-content-lg-end">
                <a href="#" class="btn btn-accent btn-lg px-4 disabled" id="btn-download" download role="button" aria-disabled="true" tabindex="-1">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="me-2" aria-hidden="true"><path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 20h14"/></svg>Download SVG
                </a>
            </div>
        </div>
        <div class="form-text" id="height-help">Changing the size only rescales the file; it does not re-trace your image.</div>
    </section>
</main>

<footer class="app-footer">
    <div class="container-xl">PicToSVG creates vector files. Always check a design in your laser/CNC software before cutting.</div>
</footer>
@endsection

@push('scripts')
    <script src="/js/pictosvg.js?v={{ @filemtime(public_path('js/pictosvg.js')) }}"></script>
@endpush
