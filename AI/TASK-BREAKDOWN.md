# Task Breakdown

Milestone: MVP (2026-11-21). Feature IDs follow `CLAUDE.md`.

## Completed in this milestone

The owner column is the planned owner from the milestone plan. This documentation pass was written in a
single session without delegation, so the owners below are not evidence of who wrote which code.

| Task | Feature IDs | Planned owner | Status | Evidence |
|---|---|---|---|---|
| Upload intake, content validation, private storage | UPLOAD-001, SYS-001 | Main session | Done | `UploadTest` (6 tests) |
| ImageMagick preparation and transparency handling | PRE-001 | Main session | Done | Conversion tests in `ConversionTest` |
| Potrace tracing adapter and presets | TRACE-001 | Main session | Done | `ConversionTest`, `PathGeometryTest` |
| Post-trace optimiser | OPT-001 | Main session | Done | `SvgOptimizationServiceTest` (6), optimisation tests |
| SVG export and validator | EXPORT-001 | Main session | Done | `SvgExportAndValidationTest` (6) |
| Download, re-sizing and authorisation | EXPORT-001, SYS-001 | Main session | Done | `DownloadAndLifecycleTest` |
| Temporary store, tokens, expiry and cleanup | SYS-001 | Main session | Done | `DownloadAndLifecycleTest` |
| AI Redraw (OpenAI image editing) | AI-001 | Main session | Done; not verified against the live API | `tests/Feature/AiRedrawTest.php` (9 tests) |
| Frontend page, script and styles | VIEW-001 | Sonnet subagent (planned) | Done | `DownloadAndLifecycleTest::test_home_page_renders_the_three_steps_without_ai`. Interactive behaviour not tested. |
| Documentation set | all | Haiku subagent (planned) | Done in one pass by the main session | Files under `docs/` and `AI/` |
| Review of geometry, security and resource bounds | all | Sonnet subagent (planned) | Not evidenced | No review record in this milestone |
| Fixture generation | TRACE-001, OPT-001 | Haiku subagent (planned) | Done | `scripts/make-fixtures.sh` and `tests/fixtures/` |

## Open tasks

Ordered by the next safe step in [docs/15-agent-handoff.md](../docs/15-agent-handoff.md).

| # | Task | Feature IDs | Blocking? | Notes |
|---|---|---|---|---|
| 1 | Add a WebP orientation test (orientation is now read by ImageMagick; KI-01 resolved) | UPLOAD-001, PRE-001 | No | PNG case is covered by `test_png_orientation_metadata_is_honoured_like_jpeg` |
| 2 | Correct fixture comment and speck formula agreement (KI-03) | PRE-001 | No | Measure first |
| 3 | Importer verification in LightBurn, VCarve/Aspire and Inkscape | EXPORT-001, TRACE-001 | Yes, before any Importer Verified claim | Needs the applications |
| 4 | Real-photograph fixture | TRACE-001 | No | Licence must be clear |
| 5 | Ubuntu, Nginx and PHP-FPM trial | SYS-001 | Yes, before deployment | Includes ImageMagick 6 question |
| 6 | Per-id lock for convert and optimise (KI-08) | SYS-001 | No | Add concurrent-request test |
| 7 | Intersection detection for cut mode | OPT-001, TRACE-001 | No | Spec first |
| 8 | Run `scripts/check.sh`, settle its `README.md` docs check, run Pint | all | No | Script exists; not run in this milestone |
| 9 | Live trial of AI Redraw with a real key | AI-001 | Needs an OpenAI API key | Measure acceptance, quality, latency, cost |
| 10 | Browser workflow tests | VIEW-001 | No | Keep to the three-step flow |
| 11 | Centerline tracing (separate feature) | TRACE-001 | No | Do not mislabel outline tracing |
| 12 | Documentation corrections (KI-12, KI-13, KI-14) | all | No | Low effort |
