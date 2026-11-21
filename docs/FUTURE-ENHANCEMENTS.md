# Future Enhancements

Nothing in this file is implemented. Each item is a candidate for a later milestone and needs a
spec, a decision (ADR where it changes architecture) and tests before work starts.

## 1. Importer verification (highest priority)

Open exported SVGs in LightBurn, Vectric Aspire / VCarve and Inkscape. Record the application
versions, the imported physical size, whether the paths import as closed shapes, and whether
Outline Cut stroke handling works. Update the verification level only with that evidence.

## 2. Real-photograph fixtures

Add permissively licensed or self-made real photographs, including a photograph of a vehicle, and
record the results honestly. The current `vehicle-photo.jpg` is synthetic.

## 3. Ubuntu deployment trial

Run the Ubuntu steps in [INSTALLATION.md](INSTALLATION.md) on a clean server. Check the ImageMagick
version and the `magick` or `convert` question, `-auto-threshold`, the PHP extensions, the upload
limits, the scheduler and the cleanup.

## 4. Intersection and overlap detection for cut paths

Detect self-intersecting and overlapping outlines in Outline Cut and warn about them. The master
specification asks for this. It is not implemented: only open-subpath detection exists.

## 5. Centerline tracing (separate feature)

True single-stroke centerlines for V-carving or single-pass engraving. This is a different algorithm
from outline tracing. It must not be labelled as outline tracing. Line Art does not do this today.

## 6. Colour tracing

Multi-colour or greyscale layers. Not planned for the MVP line.

## 7. VTracer adapter

Evaluate VTracer as a second engine. Its licence, installation route and output quality must be
checked before adoption. Requires a decision record that supersedes or extends ADR 0001.

## 8. AI Redraw follow-ups

AI Redraw is implemented (ADR 0005). Still open:

- a live trial with real photographs, measuring quality, latency and cost;
- running redraws on a queue so they do not hold a PHP worker;
- a per-site spending cap in addition to the per-IP rate limit;
- prompt variants per preset (for example bolder shapes for Outline Cut).

## 9. Queues

Move conversion to a queued job if measured load requires it. Today the queue is `sync`, and
requests are bounded by the process timeout and rate limits. Do not add a queue without
measurements.

## 10. Browser automation tests

Add a small browser test for the three-step flow (upload, convert, download). Keep it to the flow
that matters.

## 11. Concurrency and expiry improvements

- Lock a conversion id while it is being converted, so two requests cannot race on the bitmap and
  `latest.json`.
- Consider refreshing expiry on activity, or state clearly that it does not refresh.
- Move the download token out of the query string if the download flow changes.

## 12. Presentation polish

- Blade components, as the master specification suggests. Not done: the page is one view.
- A before-and-after view of the raw trace against the optimised trace.
- Accessibility review with assistive technology. Not done.

## 13. Operational tooling

- Run `scripts/check.sh` end to end and keep it passing. The script exists but was not run for this milestone.
- A pinned Pint run.
- A regular fixture check that compares node and path counts against the table in
  [SVG-OPTIMIZATION.md](SVG-OPTIMIZATION.md) §3.
