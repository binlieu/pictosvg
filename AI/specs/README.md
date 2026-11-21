# Delegation briefs — MVP milestone (2026-11-21)

Three Claude subagents were used; the main session wrote the pipeline, tests and integration.

| Brief | Model | Scope (files owned) | Result |
|---|---|---|---|
| VIEW-001 / UPLOAD-002 frontend | Sonnet | `resources/views/**`, `public/css/pictosvg.css`, `public/js/pictosvg.js`, `public/vendor/bootstrap/**`, `public/favicon.svg`. Built strictly against `docs/API.md`; no build step, no CDN, SVG preview only through a blob `<img>`. | Delivered; verified by the main session in a browser (desktop + mobile widths). |
| Documentation set | Haiku | `docs/**` (except `docs/API.md`), `AI/TASK-BREAKDOWN.md`, `AI/reports/**`. Truthfulness rule: document only what the code does; mark everything else Not implemented / Not verified. | Delivered; reconciled by the main session after the review fixes. |
| Independent review (security, resource bounds, geometry, contract) | Sonnet, read-only | No files. Findings had to be reproduced or labelled PLAUSIBLE. | 11 findings, none critical; see `docs/17-change-log.md` and `docs/16-known-issues.md` (KI-R1 … KI-R5) for what was fixed and what remains. |
