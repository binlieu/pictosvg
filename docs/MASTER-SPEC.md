# PicToSVG Master Specification

This is the product baseline. It restructures `MASTER_PROMPT_PicToSVG.md` and the project
guidance in `CLAUDE.md`. Every requirement from those sources is kept. Each area has a **Status**
column (or line) based on the code as of 2026-11-21:

- **Implemented**: the code does it and tests or the code path cover it.
- **Partially**: some of it is done. The gap is stated.
- **Not implemented**: no code exists.
- **Not verified**: the code exists, but there is no evidence for the claim.

Status is about the code, not about physical quality. See [FEATURES.md](FEATURES.md) for verification levels.

---

## 1. Identity and mission

| Item | Requirement | Status |
|---|---|---|
| Name and tagline | PicToSVG, "Turn Pictures into Precision Vectors." | Implemented |
| Type | Web-based image-to-SVG converter | Implemented |
| Purpose | Convert JPG, PNG, WebP into clean, smooth, optimised SVG for laser engraving, CNC carving and vector cutting | Implemented (JPG, PNG, WebP only) |
| Mission | Upload → Convert → Preview and adjust → Download. Useful without knowledge of vector graphics. | Implemented |
| Core principle | Make complicated vectorisation simple. Not a graphics editor. | Implemented |
| Priorities | Conversion quality, smooth lines, few nodes, compatibility with laser/CNC software | Partially (see sections 4, 5, 7) |

## 2. Technology stack

| Item | Requirement | Status |
|---|---|---|
| Backend | Latest stable Laravel | Implemented (Laravel 13.43.0) |
| Frontend | Laravel Blade | Implemented |
| CSS | Bootstrap 5 with a custom dark theme | Implemented (Bootstrap 5.3.3, vendored, custom tokens in `pictosvg.css`) |
| JavaScript | Vanilla JavaScript, lightweight libraries only where helpful | Implemented (one file, no libraries) |
| Image processing | ImageMagick or equivalent | Implemented (ImageMagick 7 CLI) |
| Vector tracing | VTracer and/or Potrace | Partially: Potrace only. VTracer not integrated (ADR 0001). |
| SVG processing | Secure parser, simplification, optimisation | Implemented (see sections 5 and 7) |
| Optional AI | Configurable image-redraw provider | Implemented with OpenAI image editing (ADR 0005); not verified against the live OpenAI API |
| Deployment | Ubuntu, Nginx, PHP-FPM | Partially: instructions written, **not verified on Ubuntu** |
| Architecture | Modular | Implemented (see [ARCHITECTURE.md](ARCHITECTURE.md)) |
| No SPA, React, Vue or Node server | Required | Implemented |
| No accounts, login, database, payments, subscriptions | MVP | Implemented |
| No unnecessary dependencies | Minimal | Implemented (see [INSTALLATION.md](INSTALLATION.md)) |
| Core works without an AI key | Required | Implemented |

## 3. User interface

### 3.1 Overall

| Item | Requirement | Status |
|---|---|---|
| Single page | One Blade page with three sections | Implemented |
| Look | Dark charcoal background, red accent buttons, clear type | Implemented (`--ps-page #16181c`, `--ps-accent #e5383b`). Visual Reviewed by the developer agent only. |
| Design references | Screenshots are inspiration, not assets | Implemented (no assets copied) |

### 3.2 Step 1: Upload Image

| Item | Requirement | Status |
|---|---|---|
| Drag and drop | Large drop area | Implemented |
| Click to browse | File picker | Implemented |
| Paste from clipboard | Optional | Implemented (document paste handler) |
| Formats | JPG/JPEG, PNG, WebP | Implemented (checked by content) |
| Size | 20 MB default | Implemented (`max_upload_mb`) |
| Pixel limit | Separately enforced | Implemented (`max_pixels`, 40 M) |
| Thumbnail | Show the original | Implemented |
| Name and dimensions | Show filename and dimensions | Implemented |
| Change or remove | Required | Implemented |
| Automatic preview | Show the original after upload | Implemented |

### 3.3 Step 2: Convert to SVG

| Item | Requirement | Status |
|---|---|---|
| Primary button | One large **Convert to SVG** | Implemented |
| Presets | Engrave (default), Outline Cut, Line Art | Implemented |
| Engrave | Clean black-and-white artwork with filled regions and details | Implemented |
| Outline Cut | Clean closed contours for cutting | Implemented, with the caveat that both edges of a drawn line are traced |
| Line Art | Clean black line artwork with minimal noise | Implemented. Not centerlines. |
| Optimize Smooth Curves | Checkbox, on by default | Implemented |
| AI Redraw | Optional button when configured; never blocks conversion; never labels thresholding as AI | Implemented: **Redraw with AI** is enabled only when a key is configured; disabled with an explanation otherwise |

### 3.4 Step 3: Preview and Download

| Item | Requirement | Status |
|---|---|---|
| Two panels | Original Image and SVG Result | Implemented |
| Zoom, pan, fit | Required | Implemented |
| Side-by-side and SVG-only | Required | Implemented |
| Backgrounds | Transparent or white | Implemented |
| Path and node counts | Shown | Implemented (nodes show "was N before optimization") |
| Output width and height | Shown | Implemented |
| Estimated file size | Shown | Implemented (exact byte count of the SVG) |
| Download button | One prominent **Download SVG** | Implemented |
| Real vector paths | Required | Implemented |

### 3.5 Presentation

| Item | Requirement | Status |
|---|---|---|
| Responsive | Side by side on desktop, stacked on mobile | Implemented (breakpoints 768 px and 992 px). Not tested on devices. |
| Keyboard | Preview focusable; `+`, `-`, `0`, arrows | Implemented |
| Accessibility | Labels, live regions, contrast, alt text | Partially: alt text, labels and live regions are present. No accessibility audit has been done. |
| Loading and errors | Truthful states | Implemented |

## 4. Vector conversion engine

| Step | Requirement | Status |
|---|---|---|
| 1 | Validate upload | Implemented (content, size, pixels) |
| 2 | Orientation and transparency | Implemented. Orientation metadata is read by ImageMagick for JPEG, PNG and WebP (KI-01, resolved). |
| 3 | Working format | Implemented (1-bit PBM) |
| 4 | Grayscale or colour normalisation | Partially: grayscale only. No colour tracing. |
| 5 | Contrast | Implemented (`-auto-level` and Image contrast) |
| 6 | Remove small isolated noise | Partially: Potrace speck removal and a post-trace size check. No raster despeckle filter. |
| 7 | Threshold by preset | Implemented (Auto OTSU or manual threshold) |
| 8 | Trace into vector geometry | Implemented (Potrace) |
| 9 | Fit smooth curves | Implemented (Potrace `--alphamax`, `--opttolerance`) |
| 10 | Remove unnecessary nodes | Implemented (flat-curve straightening and straight-run merge) |
| 11 | Preserve corners, holes, details | Implemented (`--alphamax`, Corner preservation, hole tests) |
| 12 | Validate geometry | Implemented (allow-list and geometry checks) |
| 13 | Clean SVG | Implemented |

Preset guidance:

| Requirement | Status |
|---|---|
| Prefer cubic Bézier curves and clean contours | Implemented |
| Engrave prioritises filled, reliable geometry | Implemented |
| Outline Cut prioritises closed, stable, non-duplicated contours | Partially: closure and duplicates are handled. Overlap and intersection detection are not implemented. |
| Line Art traces black artwork faithfully and does not claim centerlines | Implemented |
| Separate centerline feature for later | Not implemented ([FUTURE-ENHANCEMENTS.md](FUTURE-ENHANCEMENTS.md)) |

## 5. Automatic SVG optimisation

| Item | Requirement | Status |
|---|---|---|
| Path simplification | Required | Implemented |
| Bézier curve fitting | Required | Implemented (Potrace) |
| Excess-node reduction | Required | Implemented |
| Tiny speck removal | Required | Implemented |
| Small artifact removal | Required | Implemented |
| Nearly straight segment simplification | Required | Implemented |
| Duplicate geometry detection | Required | Partially: exact duplicate contours only |
| Overlapping path cleanup | Required | Not implemented. Overlaps are not merged; merging can change holes and cutting behaviour. |
| Appropriate path closure | Required | Partially: Potrace closes contours. Open contours in Outline Cut are reported. |
| Decimal precision | Required | Implemented (1 decimal place in viewBox units) |
| Sharp corners preserved | Required | Implemented |
| Interior detail preserved | Required | Implemented |
| Tolerances by resolution and settings | Required | Implemented (`simplifyTolerance` scales above 2000 px) |
| No blind merging | Required | Implemented (shapes are never merged) |
| No aggressive optimisation for smaller counts | Required | Implemented (conservative defaults) |

## 6. Simple adjustment controls

| Item | Requirement | Status |
|---|---|---|
| Detail | 7/10 default | Implemented |
| Smoothing | 8/10 default | Implemented |
| Noise Removal | 5/10 default | Implemented |
| Advanced Settings | Collapsible section | Implemented |
| Advanced: threshold | Black-and-white threshold | Implemented (Auto or 1–99 %) |
| Advanced: contrast | Image contrast | Implemented (−50 to 50) |
| Advanced: invert | Invert black/white | Implemented |
| Advanced: tolerance | Curve simplification tolerance | Implemented (0–5, or Auto) |
| Advanced: speck | Minimum speck size | Implemented (0–1000, or Auto) |
| Advanced: corners | Corner preservation | Implemented (0–10, or Auto) |
| Advanced: outline-only | Outline-only output | Implemented |
| Advanced: transparent background | Transparent background | Implemented |
| Debounce | Debounced re-conversion | Implemented (450 ms, after the first conversion) |
| Loading indicator | Required | Implemented |
| Reset Settings | Reset without discarding the image | Implemented |
| Good results without adjustment | Required | Implemented for the fixtures (see [SVG-OPTIMIZATION.md](SVG-OPTIMIZATION.md) §3). Not evaluated on real images. |

## 7. SVG output requirements

| Item | Requirement | Status |
|---|---|---|
| Valid SVG markup | Required | Implemented (validator) |
| Actual vector geometry | Required | Implemented |
| Correct viewBox | Required | Implemented (working bitmap size) |
| Aspect ratio | Preserved | Implemented |
| Real-world dimensions | mm or inches | Implemented |
| No unnecessary metadata | Required | Implemented (no metadata element) |
| No embedded raster | Required | Implemented |
| No unsupported filters | Required | Implemented |
| Standard path elements | Required | Implemented |
| Safe content | Required | Implemented (allow-list) |
| Holes and fill rules | Preserved | Implemented (non-zero rule, tests) |
| No group transforms | Required | Implemented |
| Black-and-white engraving | Required | Implemented |
| Output width and unit | Default 150 mm; mm or inches | Implemented |
| Height from aspect ratio | Automatic | Implemented |
| Physical sizing does not deform | Required | Implemented (only `width`/`height` attributes change) |
| Importer compatibility: LightBurn, Vectric Aspire / VCarve, Inkscape | Target | Not verified. No importer has been run. |
| Do not generate G-code | Required | Implemented (no G-code code) |
| Cut-mode warnings: open paths | Required | Implemented |
| Cut-mode warnings: intersections | Required | Not implemented |
| Not presented as a cut-safety guarantee | Required | Implemented (warning text and docs) |

## 8. Optional AI redraw

| Item | Requirement | Status |
|---|---|---|
| Modular integration layer | Required | Implemented (`OptionalAiRedrawService`, isolated from the conversion engine) |
| Provider adapter | Required when AI is used | Implemented: OpenAI image editing; not verified against the live OpenAI API |
| Redraw flow | Original → AI Redraw → trace → optimise | Implemented (tested with a faked API) |
| Original and redrawn versions | Distinguishable and selectable | Implemented (**Picture to trace** switch; `source` parameter) |
| Never overwrite the original | Required | Implemented (redraw stored as a separate file; covered by tests) |
| Credentials on the server only | Required | Implemented (config only; not returned to clients) |
| Gate when not configured | Required | Implemented (disabled button, status endpoint, 503 endpoint) |
| Explain AI may reinterpret details | Required | Implemented (note text) |
| Privacy, retention and cost documentation | Required | Implemented (README, AI-001, ADR 0005); the redraw follows the same 60-minute expiry as the upload |
| Standard conversion needs no AI | Required | Implemented |

## 9. Backend architecture

| Item | Requirement | Status |
|---|---|---|
| Upload controller | `ImageUploadController` | Implemented |
| Conversion controller | `SvgConversionController` | Implemented (`convert` and `optimize`) |
| Download controller | `SvgDownloadController` | Implemented |
| Services | `ImagePreprocessingService`, `VectorTracingService`, `SvgOptimizationService`, `SvgValidationService`, `SvgExportService`, `OptionalAiRedrawService` | Implemented |
| Frontend | One main Blade page | Implemented |
| Reusable Blade components | Suggested | Not implemented (one view) |
| One JavaScript module | Uploads and preview | Implemented (`public/js/pictosvg.js`) |
| Bootstrap 5 styling | Required | Implemented |
| Endpoints | upload, convert, optimise, download `{temporaryId}` | Implemented |
| Secure temporary directories | Required | Implemented |
| Limits: size, dimensions, MIME, timeouts, resources | Required | Implemented (rate limits are per IP) |
| Prevent arbitrary file access, command injection, unsafe SVG, cross-user access | Required | Implemented (see [ARCHITECTURE.md](ARCHITECTURE.md) §7) |
| Automatic expiry and cleanup | Required | Implemented |
| Laravel secure process execution | Required | Implemented (`Process` with arrays and timeouts) |
| No system paths or commands in responses | Required | Implemented (tested) |
| Stateless, no database | Preferred | Implemented |
| Queues as later enhancement | Optional | Not implemented (sync queue) |

## 10. UI and UX design

| Item | Requirement | Status |
|---|---|---|
| Commercial feel, dark background, red buttons, rounded panels | Required | Implemented (`--ps-radius 14px`). Visual Reviewed by the developer agent only. |
| Responsive layouts | Required | Implemented. Not device-tested. |
| Large preview area | Required | Implemented |
| Clear upload instructions | Required | Implemented |
| Meaningful loading states and errors | Required | Implemented |
| No technical jargon in the default UI | Required | Implemented. Advanced labels use some technical words. |
| Three-interaction workflow | Required | Implemented |

## 11. Testing and quality

| Area | Requirement | Status |
|---|---|---|
| Upload validation | Automated tests | Implemented (`UploadTest`) |
| Conversion success | Automated tests | Implemented (`ConversionTest`) |
| SVG XML validity | Automated tests | Implemented |
| Physical dimensions and aspect ratio | Automated tests | Implemented |
| Transparent image handling | Automated tests | Implemented |
| Background removal behaviour | Automated tests | Partially: transparent flattening and alpha-shape branch are tested. No separate background removal feature. |
| Path simplification | Automated tests | Implemented (`SvgOptimizationServiceTest`) |
| Tiny noise cleanup | Automated tests | Implemented |
| Preservation of holes | Automated tests | Implemented |
| Cut contour closure | Automated tests | Implemented |
| Download authorisation | Automated tests | Implemented |
| Temporary file cleanup | Automated tests | Implemented |
| Invalid and malicious uploads | Automated tests | Implemented |
| Missing AI configuration | Automated tests | Implemented |
| Fixture: high-contrast logo | Test | Implemented (`logo.png`) |
| Fixture: transparent PNG logo | Test | Implemented (`transparent-logo.png`) |
| Fixture: text-heavy image | Test | Implemented (`text.png`) |
| Fixture: detailed line drawing | Test | Implemented (`line-drawing.png`) |
| Fixture: photograph containing a vehicle | Test | Partially: `vehicle-photo.jpg` is **synthetic** |
| Fixture: complex illustration | Test | Implemented (`illustration.png`) |
| Visual check of smoothing | Required | Visual Reviewed (developer agent) only |
| Browser workflow checks | Where they add value | Not implemented |
| `scripts/check.sh` quality gate | Required by project guidance | Present. Not run for this milestone. Its docs step expects `README.md`, which is absent. |
| Pint formatting check | Where installed | Not run |
| Real-import checks (LightBurn, VCarve/Aspire, Inkscape) | Where possible | Not verified |

## 12. Development phases

| Phase | Scope | Status |
|---|---|---|
| Phase 1: working MVP | Laravel app, single page, upload, black-and-white conversion, real tracing, optimisation, side-by-side preview, download, mm and inch sizing | Implemented (Ubuntu deployment not verified) |
| Phase 2: improved processing | Detail, Smoothing and Noise Removal sliders; additional presets; Advanced Settings; improved contour analysis; better comparison | Partially: sliders, presets and Advanced Settings are done. Contour analysis is limited to Potrace and the post-trace steps. The comparison is side by side only. |
| Phase 3: optional AI | AI redraw, subject simplification, original-versus-redrawn comparison, tracing the AI output | Implemented with OpenAI image editing; not verified against the live OpenAI API |

## 13. Documentation

| File | Status |
|---|---|
| PROJECT-OVERVIEW.md, ARCHITECTURE.md, FEATURES.md, IMAGE-PROCESSING.md, SVG-OPTIMIZATION.md, API.md, INSTALLATION.md, TESTING.md, FUTURE-ENHANCEMENTS.md | Implemented (this set) |
| Ubuntu and Nginx installation with the required tools | Partially: written, not tested |
| Actual commands, dependencies, configuration and tested settings | Implemented |

## 14. Development rules

| Rule | Status |
|---|---|
| Build a working website, not a prototype | Implemented (MVP) |
| Established tracing libraries | Implemented (Potrace) |
| Smooth SVG quality over feature count | Implemented |
| Reusable Laravel services | Implemented |
| Real functionality, no fake results | Implemented |
| Never embed a PNG or JPG in an SVG | Implemented |
| Minimal JavaScript | Implemented |
| No database unless needed | Implemented |
| Optional AI isolated from standard conversion | Implemented |
| Test actual vector exports | Implemented |
| Track progress in documentation | Implemented (this set) |
| Complete and verify the MVP before expanding | Partially: the MVP is complete, but importer and machine verification are not done |

## 15. Project guidance (`CLAUDE.md`) items not yet met

| Item | Status |
|---|---|
| `scripts/check.sh` quality gate | Present; not run for this milestone |
| `AGENTS.md` startup contract | Not present |
| `AI/TASK-BREAKDOWN.md` and `AI/reports/` | Written for this milestone |
| Feature specs under `docs/features/` | Written for this milestone (eight) |
| Importer verification evidence | Not verified |
| Visual before and after comparison for representative images | Visual Reviewed (developer agent) for fixtures only |


## Addendum (2026-11-21): Color preset

Added at the owner's request, beyond the original master prompt: a fourth preset, **Color**, that
converts flat-color pictures into SVG shapes in the same colors. Status: Implemented and Automated
Tested; not importer-verified. Specification: [TRACE-002](features/TRACE-002-color-conversion.md).
