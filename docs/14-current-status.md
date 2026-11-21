# Current Status

Date: 2026-11-21

## Summary

The MVP is implemented: upload, validate, prepare, trace with Potrace, optimise, preview, export
a physically sized SVG, and download. No database, accounts or AI provider are used.

Test status: **71 tests pass** (`php artisan test`), as reported for this milestone. The count
was confirmed by a static count of the test methods. The suite was not re-run for this documentation pass.

Verification:
- Automated Tested: yes (unit and feature tests, real conversions).
- Visual Reviewed: fixture outputs, by the developer agent (not a human review).
- Importer Verified: **Not Verified**.
- Machine Verified: **Not Verified**.
- Ubuntu deployment: **Not Verified**.

## Feature status

| ID | Status |
|---|---|
| UPLOAD-001 | Implemented |
| PRE-001 | Implemented |
| TRACE-001 | Implemented (Potrace only) |
| OPT-001 | Implemented (no overlap cleanup) |
| VIEW-001 | Implemented (no browser tests) |
| EXPORT-001 | Implemented (no importer checks) |
| AI-001 | Implemented with OpenAI image editing; tested with a faked API only — not verified against the live API |
| SYS-001 | Implemented (no Ubuntu test) |

## Components

| Area | Status |
|---|---|
| Laravel 13 application, routes, controllers, requests | Implemented |
| ImageMagick 7 CLI preparation | Implemented |
| Potrace 1.16 tracing | Implemented |
| VTracer | Not implemented |
| Post-trace optimiser | Implemented |
| SVG export and validation | Implemented |
| Temporary store, tokens, expiry and cleanup | Implemented |
| Rate limits (per IP) | Implemented, not tested |
| Blade page, JavaScript, CSS | Implemented |
| Bootstrap 5.3.3 (vendored) | Implemented |
| Queues | Not used (sync) |
| Database | Not used |
| Centerline tracing | Not implemented |
| Colour tracing | Not implemented |
| Intersection detection | Not implemented |
| AI Redraw live verification | Not done (no OpenAI key available) |
| `scripts/check.sh` | Present; not run for this milestone. Its docs step expects `README.md`, which is absent. |
| Ubuntu, Nginx and PHP-FPM instructions | Written, not tested |

## Measured on the fixtures

See [SVG-OPTIMIZATION.md](SVG-OPTIMIZATION.md) §3. Conversion takes 0.6–1.5 s per image on the
development machine.

## Documentation set

- Product baseline: [MASTER-SPEC.md](MASTER-SPEC.md).
- Overview, architecture, features, algorithms, installation, testing, future work: see the links
  in [PROJECT-OVERVIEW.md](PROJECT-OVERVIEW.md).
- Specs: [features/](features/). ADRs: [adr/](adr/).
- API: [API.md](API.md).
- Known issues: [16-known-issues.md](16-known-issues.md). Handoff: [15-agent-handoff.md](15-agent-handoff.md).
- Change log: [17-change-log.md](17-change-log.md).

## Not in this milestone

Importer verification, real-photo testing, Ubuntu trial, intersection detection, AI provider,
browser tests, the quality gate script and Pint formatting.
