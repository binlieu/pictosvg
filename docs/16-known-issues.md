# Known Issues

Last reviewed 2026-11-21. Severity is the author's judgement for an MVP used for laser and CNC files.
"Verified" means a check was run. "Derived" means the claim follows from reading the code and was
not reproduced.

## High priority

### KI-01: (Resolved 2026-11-21) Orientation metadata

Originally the reported width/height were swapped only for JPEG via the PHP `exif` extension, so a
PNG or WebP carrying an orientation tag was rotated by `-auto-orient` and then stretched (confirmed
by the independent review with a PNG `eXIf` chunk). `ImagePreprocessingService::inspect()` now asks
ImageMagick for `%[orientation]` for every format, so the reported size always matches what is
traced. Covered by `UploadTest::test_png_orientation_metadata_is_honoured_like_jpeg`,
`UploadTest::test_reports_orientation_corrected_dimensions` and
`ConversionTest::test_rotated_jpeg_is_traced_upright`. WebP orientation is handled by the same code
path but has no dedicated test.

### KI-02: Ubuntu and ImageMagick 6 are not verified

`PICTOSVG_MAGICK_BIN` defaults to `magick`, which is ImageMagick 7. Ubuntu's package is normally
ImageMagick 6, which provides `convert`. The arguments `-auto-threshold OTSU`, `-limit` and the
`pbm:` output have not been checked against ImageMagick 6. Ubuntu deployment as a whole has not been tested.

## Medium priority

### KI-03: Fixture comment disagrees with the speck formula

`scripts/make-fixtures.sh` says a 3 × 3 dot "needs Noise Removal 7+". By the formula in
[IMAGE-PROCESSING.md](IMAGE-PROCESSING.md) §2.3 (derived, not measured), that dot is removed from
Noise Removal 9 upward. The test `ConversionTest::test_noise_removal_drops_specks_and_zero_keeps_them`
asserts only the default (kept) and 10 (removed). Correct the comment or the formula after measuring.

### KI-04: Outline Cut traces both edges of a line

A drawn line of width *w* becomes two closed cut contours. This is inherent to outline tracing. The
warning says so. Users who want one cut per line need centerline tracing (not implemented).

### KI-05: No intersection or overlap detection

Only open-subpath detection exists. The master specification asks for intersection warnings in cut
mode. Not implemented.

### KI-06: Download token is in the query string

`GET /api/svg/download/{id}?token=…` can appear in web-server access logs and in browser history. The
risk is limited to the 60-minute lifetime. Moving the token to a header needs a different download
flow. Not changed.

### KI-07: Expiry is fixed at upload

The TTL counts from `meta.json`, not from the last use. A user who works for more than an hour must
upload again. This is documented, not hidden. It is also a usability issue.

### KI-08: No lock on a conversion id

Two requests for the same id can run at once. They can write `bitmap-*.pbm`, `latest.json` and
`result-*.svg` concurrently. Writes are atomic per file, and the pipeline deletes other bitmaps when it
writes a new one, so a race can delete a bitmap another request is about to read. Not reproduced.

### KI-09: Rotating `APP_KEY` invalidates all conversions

This is by design. Tokens depend on the key. Users get `invalid_token` and must upload again.

### KI-10: Photographs are approximate, and the photo fixture is synthetic

`vehicle-photo.jpg` is a generated image with gradients and noise. No real photograph has been tested.
The `+noise Gaussian` step in `make-fixtures.sh` has no seed, so regenerating the fixture changes its
pixels and may change the asserted counts.

### KI-11: Importer and machine behaviour not verified

None of LightBurn, Vectric Aspire / VCarve or Inkscape has been run. The outline stroke width is not
known to be handled correctly by cutting software. No machine has been used.

## Low priority

### KI-12: Documentation disagrees with the code in places

- `docs/API.md` shows the `/api/ai/status` message as "AI Redraw is not configured on this server."
  The code returns "…Standard conversion works without it." appended. The code is the source of truth.
- `docs/API.md` names the download parameter `{id}`. The route parameter is `temporaryId`.
- The master specification says the AI button "may appear when configured". It is always shown,
  disabled when not configured.

### KI-13: Test comments cite IDs that have no spec

`DownloadAndLifecycleTest` uses `EXPORT-003`, `SYS-002` and `AI-001`. `EXPORT-002` appears in
`PathGeometry.php` and `PathGeometryTest.php`. `UPLOAD-002` appears in `public/js/pictosvg.js`. This
milestone's specs use the `-001` form only. The mapping is in [FEATURES.md](FEATURES.md).

### KI-14: Result-version test bound is looser than the setting

`pictosvg.keep_versions` is 5. `DownloadAndLifecycleTest::test_only_a_bounded_number_of_result_versions_is_kept`
asserts at most 6.

### KI-15: Blank-image test writes into the fixtures folder

`ConversionTest::test_blank_image_gives_an_actionable_error` copies a temporary file into
`tests/fixtures/__blank.png` and removes it in `finally`. The file exists only during the test. It is a
hygiene issue, not a defect.

### KI-16: Outline stroke formula changed during this milestone

`SvgExportService` computed the outline hairline as `max(width, height) / 1000` at one point during this
milestone and as `/ 600` in the version documented here (about 0.17 % of the drawing size). Outline
exports made before the change have a different stroke width. No test asserts the stroke value.

### KI-17: Unused and missing items

- `POST /api/svg/optimize` exists, but the web page always calls `/api/svg/convert`.
- `scripts/check.sh` exists but was not run for this milestone. Its documentation step expects a `README.md`, which does not exist, so the gate fails there.
- Laravel Pint has not been run for this milestone.
- `AGENTS.md`, `AI/specs/` (empty) and `app/Console/Commands/` (empty) are absent or empty.
- `database/migrations/` exists and is empty.
- `scripts/test-summary.sh` expects the final line of `php artisan test` output to be JSON. The
  format has not been verified.

### KI-18: `.env.example` is not production-safe

`APP_DEBUG=true` and `LOG_LEVEL=debug`. Set `APP_DEBUG=false` in production. See [INSTALLATION.md](INSTALLATION.md).

### KI-19: Licensing review for Potrace distribution is not done

Potrace is GPL-2.0-or-later and is run as a separate program. Any packaging that bundles Potrace needs a
licence review. See [ADR 0001](adr/0001-potrace-as-tracing-engine.md).

### KI-20: No browser or device tests

Drag and drop, paste, zoom, pan, view switching and mobile layouts have never been exercised by an
automated browser test or on a device. Layout claims for phone widths are from CSS breakpoints only.

## Added after the independent review (2026-11-21)

### KI-R1: No global concurrency cap on conversions

Each ImageMagick run may use up to the configured memory limit (default 1 GiB) and conversions are
bounded only by per-IP rate limits (20 uploads/min, 180 conversions/min) and the process timeout.
Many simultaneous conversions can exhaust a small server. Mitigation today: cap PHP-FPM
`pm.max_children` and lower `PICTOSVG_MAGICK_MEMORY`. A semaphore or queue is not implemented.

### KI-R2: A pruned result version answers `404 expired`

Only the 5 newest result versions per image are kept. Downloading an older `version` returns the
same `expired` code as an expired image, and because the download is a plain link the user sees
raw JSON. Not fixed.

### KI-R3: The download token is in the query string

It can appear in web-server access logs. The page and the download response send
`Referrer-Policy: no-referrer`; log scrubbing is left to the deployment.

### KI-R4: Slider dragging can still hit the rate limit

Re-conversion is debounced by about 450 ms; a long continuous drag from one IP can exceed
180 requests/min and receive a 429 ("Too many requests").

### KI-R5: Very noisy large photos are refused rather than converted

When the raw trace exceeds 3 MB the conversion stops with a "too complex" message
(`ConversionTest::test_overly_complex_traces_are_refused_before_they_exhaust_memory`). Raising
Noise Removal or lowering Detail usually gets a result.
