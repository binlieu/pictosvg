# Features

Status as of 2026-11-21. Each feature has a spec in [features/](features/). Test names are real
methods in `tests/`.

## Verification levels

Only the levels below are used. A level is claimed only when the evidence named in it exists.

| Level | Meaning | Evidence in this milestone |
|---|---|---|
| Implemented | The code exists and runs. | Yes, for all features. |
| Automated Tested | Unit or feature tests exercise the behaviour. Tests use real conversions, not mocks of the tracer. | 62 tests pass (`php artisan test`, as reported for this milestone). |
| Visual Reviewed | Output was rendered and inspected. This is a developer-agent review, not a human review. | Fixture outputs only. |
| Importer Verified | Output was opened in LightBurn, Vectric Aspire / VCarve or Inkscape. | **Not Verified.** None of these applications is installed. |
| Machine Verified | Output was cut or engraved on a machine. | **Not Verified.** |
| Not Verified | No evidence. | Used for anything else. |

## Feature table

| ID | Area | Status | Verification | Spec |
|---|---|---|---|---|
| UPLOAD-001 | Image intake: drag and drop, browse, paste, validation, thumbnail, change and remove | Implemented | Automated Tested (API and validation). Browser interaction: Not Verified. | [UPLOAD-001](features/UPLOAD-001-image-intake.md) |
| PRE-001 | Raster preprocessing: orientation, transparency, working size, grey, contrast, blur, threshold, invert | Implemented | Automated Tested (through conversions). Visual Reviewed (fixtures). | [PRE-001](features/PRE-001-raster-preprocessing.md) |
| TRACE-001 | Vector conversion: Potrace tracing, presets, holes, corners, Line Art, Outline Cut | Implemented | Automated Tested. Visual Reviewed (fixtures). Importer Verified: Not Verified. | [TRACE-001](features/TRACE-001-vector-conversion.md) |
| OPT-001 | Path optimisation: curve fitting, straight-run merge, speck removal, duplicate removal | Implemented (partial against the spec's "overlap cleanup") | Automated Tested. Visual Reviewed (fixtures). | [OPT-001](features/OPT-001-path-optimization.md) |
| VIEW-001 | Preview and controls: side-by-side, zoom, pan, backgrounds, sliders, Advanced Settings, debounce | Implemented | Automated Tested only for the rendered page's markup. Interactive behaviour: Not Verified (no browser tests). | [VIEW-001](features/VIEW-001-preview-and-controls.md) |
| EXPORT-001 | SVG output: physical size, viewBox, allow-list validation, download, re-dimensioning | Implemented | Automated Tested. Visual Reviewed (fixtures). Importer Verified: Not Verified. | [EXPORT-001](features/EXPORT-001-svg-output.md) |
| AI-001 | Optional AI Redraw (OpenAI image editing): redraw, compare, choose which picture to trace | Implemented; enabled only with `OPENAI_API_KEY`. | Automated Tested (faked API); browser-tried against a local stand-in; **Not Verified against the live API**. | [AI-001](features/AI-001-optional-redraw.md) |
| SYS-001 | Reliability and security: temporary storage, tokens, expiry, cleanup, rate limits, process limits, error hygiene | Implemented | Automated Tested. Ubuntu deployment: Not Verified. | [SYS-001](features/SYS-001-reliability-security.md) |

## Notes on the table

- "Automated Tested" means the test methods listed in each feature spec pass in the test suite.
  Their results are as reported for this milestone; see [TESTING.md](TESTING.md).
- Several test comments use IDs such as `EXPORT-002`, `EXPORT-003`, `SYS-002` and `UPLOAD-002`. The
  specs in this set use the `-001` form. See [16-known-issues.md](16-known-issues.md).
- No feature is marked Importer Verified or Machine Verified. Do not infer physical quality from a
  preview.
