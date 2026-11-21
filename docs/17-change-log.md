# Change Log

## 2026-11-21: MVP

Scope: first complete vertical slice from upload to downloaded, physically sized SVG.

Added:
- Laravel 13 application with Blade page, one JavaScript file and one stylesheet (dark theme, red
  accents, responsive layout).
- Upload intake with content validation, a 20 MB byte limit and a 40 M pixel limit.
- ImageMagick preparation: orientation, transparency handling, grey, working size, contrast, blur, invert and
  threshold, with resource limits.
- Potrace 1.16 tracing with raw and curve-fitted passes, with three presets: Engrave, Outline Cut and Line Art.
- Post-trace optimiser: speck and small hole removal, flat-curve straightening, straight-run merge and exact
  duplicate removal.
- SVG export in millimetres or inches with a correct viewBox and aspect ratio, and a validator that
  allow-lists elements, attributes and values.
- Download endpoint with token authorisation, safe headers and re-sizing that rewrites only width and height.
- Private temporary storage with opaque ids, HMAC tokens, 60-minute expiry, scheduled and sweep-based cleanup,
  and per-IP rate limits.
- AI redraw gating (configuration, status, disabled button, 503 endpoint). No provider.
- Test suite of 54 tests (19 unit, 35 feature) using real conversions and synthetic fixtures.
- Documentation set: product spec, architecture, features, image processing, SVG optimisation, installation,
  testing, future enhancements, four ADRs, eight feature specs, status, handoff, known issues and this log.

Verification:
- Automated Tested: 54 tests reported passing.
- Visual Reviewed: fixture outputs (developer agent).
- Importer Verified: Not Verified.
- Machine Verified: Not Verified.
- Ubuntu deployment: Not Verified.

Known issues of note: see [16-known-issues.md](16-known-issues.md), especially KI-01 (EXIF orientation depends on the
`exif` extension).

## 2026-11-21 — Independent review fixes (SYS-001, PRE-001, EXPORT-001)

- Orientation metadata is honoured for PNG and WebP as well as JPEG (was: JPEG only; rotated PNGs were stretched).
- Raw tracer output over 3 MB is refused before parsing (measured: a 3000x2000 heavy-noise JPEG is refused at 22 MB peak PHP memory under a 128 MB limit; with a 6 MB cap the same image peaked at 115 MB); the raw node count is measured directly instead of re-exporting and re-validating the raw trace.
- Prepared-bitmap cache files newer than 120 s are no longer deleted by a concurrent conversion.
- Width must be at least 0.01 on convert and download (tiny widths previously produced a 500).
- Local reverse proxy is trusted so rate limits use the real client IP; conversion limit raised to 180/min.
- Straight-run merging uses a bounded look-ahead window (linear time on pathological input).
- Download response and page send `Referrer-Policy: no-referrer`; `.env.example` ships `APP_DEBUG=false`.
- Tests: 54 passing (19 unit, 35 feature).
- Public repository created: https://github.com/binlieu/pictosvg

## 2026-11-21 — AI Suggest Settings (AI-002) and license

- Optional **AI Suggest Settings**: `POST /api/ai/suggest`, `AiSuggestService`, `ClaudeSettingsAdvisor`
  (official `anthropic-ai/sdk` ^1.12, model `claude-opus-5-5` by default). Enabled only with `ANTHROPIC_API_KEY`.
- The disabled "AI Redraw" button was replaced by this button. Image redraw remains not implemented.
- `ConversionPipeline::prepareBitmap()` extracted so the AI is shown the exact bitmap that is traced.
- Tests: 61 passing (19 unit, 42 feature). The live Anthropic API has not been called.
- Repository licensed under Apache-2.0.

## 2026-11-21 — AI Redraw with OpenAI (AI-001); Claude feature removed

- **Removed** "AI Suggest Settings" (AI-002), its endpoint `POST /api/ai/suggest`, its tests and the
  `anthropic-ai/sdk` dependency, at the owner's request.
- **Added** AI Redraw: `OptionalAiRedrawService` now calls OpenAI image editing; `POST /api/ai/redraw`
  stores a redraw beside the original, `GET /api/ai/redraw/{id}` serves it, and
  `/api/svg/convert` accepts `source: original|redraw`. UI: **Redraw with AI** button and a
  **Picture to trace** switch for comparison. See ADR 0005 and `docs/features/AI-001-optional-redraw.md`.
- Config: `OPENAI_API_KEY`, `PICTOSVG_AI_IMAGE_MODEL`, `PICTOSVG_AI_IMAGE_QUALITY`, `PICTOSVG_AI_TIMEOUT`,
  `PICTOSVG_AI_BASE_URL`. `PICTOSVG_AI_PROVIDER` / `PICTOSVG_AI_API_KEY` no longer exist.
- Tests: 62 passing (19 unit, 43 feature). The live OpenAI API has not been called.

## 2026-11-21 — Color preset (TRACE-002)

- New **Color** preset: flat-color pictures become colored vector layers in the picture's own colors
  (`ColorPalette`, `ConversionPipeline::traceColour`, `SvgExportService::buildColor`).
- New request fields `colors` (2–16 or automatic) and response field `stats.colors`.
- UI: fourth preset card, "Number of colors" and "Leave out the background color" controls.
- Page script and stylesheet links now carry a version query so browsers pick up updates.
- Tests: 71 passing (19 unit, 52 feature).
