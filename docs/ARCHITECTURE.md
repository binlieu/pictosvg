# Architecture

Status: Implemented (MVP, 2026-11-21).

PicToSVG is a stateless Laravel 13 application. It has no database, no accounts and no queue
worker. A conversion is a set of files in a private temporary directory, addressed by an opaque
id and a token.

## 1. Components

| Layer | Location | Responsibility |
|---|---|---|
| Routes (web) | `routes/web.php` | `/` renders the single page. |
| Routes (API) | `routes/api.php` | Upload, convert, optimise, download, AI status and AI redraw. |
| Routes (console) | `routes/console.php` | `pictosvg:cleanup` command and its schedule (every ten minutes). |
| Controllers | `app/Http/Controllers/` | `ImageUploadController` (invokable), `SvgConversionController` (`convert`, `optimize`), `SvgDownloadController` (invokable), `AiRedrawController` (`status`, `redraw`). |
| Request | `app/Http/Requests/ConvertRequest.php` | Validates id, token and all conversion settings. |
| Pipeline | `app/Services/ConversionPipeline.php` | Sequences the stages below and caches the bitmap. |
| Settings | `app/Services/ConversionSettings.php` | Maps plain controls to engine settings. |
| Image processing | `app/Services/ImageProcessing/ImagePreprocessingService.php` | Content validation, transparency analysis, ImageMagick preparation. |
| Tracing | `app/Services/Tracing/VectorTracingService.php` | Potrace adapter and SVG reader. |
| SVG | `app/Services/Svg/` | `SvgOptimizationService`, `SvgExportService`, `SvgValidationService`, `PathGeometry`. |
| AI (gated) | `app/Services/Ai/OptionalAiRedrawService.php` | Status and always-unavailable redraw. Not on the standard path. |
| Process helper | `app/Support/ExternalTool.php` | Runs an external binary with an argument array, timeout and neutral working directory. |
| Storage | `app/Support/TemporaryFiles/ConversionStore.php` | Ids, tokens, directory lifecycle, expiry and cleanup. |
| Errors | `app/Exceptions/PicToSvgException.php` | User-facing error codes and JSON rendering. |
| Wiring | `app/Providers/AppServiceProvider.php` | Binds `ConversionStore` (root, TTL, `app.key`) and defines the rate limiters. |
| Config | `config/pictosvg.php` | Tool paths, limits, TTL, defaults and AI keys. Environment keys are listed in [INSTALLATION.md](INSTALLATION.md). |
| Frontend | `resources/views/home.blade.php`, `layouts/app.blade.php`, `public/js/pictosvg.js`, `public/css/pictosvg.css` | One Blade page, one plain JavaScript file, one stylesheet. |
| Vendored assets | `public/vendor/bootstrap/` | Bootstrap 5.3.3 CSS and JS bundle. Served from the same origin. No CDN. |

`app/Console/Commands/` and `database/migrations/` exist but are empty. The command lives in
`routes/console.php`.

## 2. Request flow

### 2.1 Upload (`POST /api/images/upload`)

1. Reject if there is no single file, or if the file is invalid or larger than `max_upload_mb`
   (20 MB). Error code `image_too_large`.
2. Inspect the content (`ImagePreprocessingService::inspect`): MIME from `finfo` (must be JPEG,
   PNG or WebP); `getimagesize` type must agree; width and height at least 8 px; decoded pixels at
   most `max_pixels` (40 M). Orientation metadata (EXIF in JPEG, PNG and WebP) is read with
   ImageMagick (`%[orientation]`); orientations 5–8 swap the reported width and height. PHP's `exif`
   extension is not needed.
3. Run the cleanup sweep with 1-in-20 probability (`Lottery`).
4. Create `storage/app/private/pictosvg/<id>/` with mode 0700. Move the upload to `original.bin`
   (mode 0600). Write `meta.json` with the inspection facts and a sanitised display name.
5. Return `201` with `id`, `token`, `name`, `width`, `height`, `bytes`, `mime` and `expires_in`.

### 2.2 Convert and optimise (`POST /api/svg/convert`, `POST /api/svg/optimize`)

1. `ConvertRequest` validates the id, token and settings.
2. `ConversionStore::authorize` checks the id format, the HMAC token (constant-time comparison),
   the existence of `meta.json` and expiry. An expired directory is deleted on access.
3. `ConversionSettings::fromArray` builds the settings.
4. `ConversionPipeline::convert`:
   a. Read or create `analysis.json` (transparency decision).
   b. Create `bitmap-<key>.pbm` with ImageMagick unless a matching bitmap exists.
   c. Run Potrace raw (`--longcurve`). Raw output over 3 MB is refused. Count nodes (`nodes_before`)
      directly from the raw trace.
   d. If Smoothing is on, run Potrace curve-fitted and optimise.
   e. Export and validate the final SVG. Reject results over 15 MB.
   f. Write `result-<version>.svg` atomically, update `latest.json` and prune old versions.
   g. Return the JSON body: `id`, `version`, `svg`, `stats`, `warnings`, `engine` (`potrace`).

`/api/svg/optimize` runs the same code with `requireExisting = true`. It returns `409` when no
conversion has been stored yet. The web UI always calls `/api/svg/convert`.

### 2.3 Download (`GET /api/svg/download/{temporaryId}`)

1. Authorise id and token (same as above).
2. Validate the optional `version` (12 hex), `width` (numeric, at least 0.01, at most 100000) and
   `unit` (`mm` or `in`, required with `width`).
3. Read `result-<version>.svg` (default `latest.json`). Missing file returns `404 expired`.
4. If `width` is given, rewrite only the root `width` and `height`.
5. Validate the bytes again. Never serve a document that fails validation.
6. Return the file with `Content-Type: image/svg+xml; charset=utf-8`,
   `Content-Disposition: attachment; filename="<name>-pictosvg.svg"`, `X-Content-Type-Options: nosniff`,
   `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` and
   `Cache-Control: private, no-store` and `Referrer-Policy: no-referrer`.

### 2.4 AI status and redraw

`GET /api/ai/status` reports whether AI is configured. `POST /api/ai/redraw` authorises the
conversion, then always throws `ai_unavailable` (503). See [ADR 0004](adr/0004-ai-redraw-gated-not-implemented.md).

## 3. Frontend

- `home.blade.php` embeds a JSON config block (`pictosvg-config`) with the upload limit, AI
  status and defaults. The script reads it on load.
- Upload uses `XMLHttpRequest` so that progress can be shown. Drag and drop, click to browse and
  paste are supported. Drops outside the zone are prevented from navigating the browser.
- Conversion uses `fetch` with an `AbortController`. A request counter discards stale responses.
  Automatic re-conversion is debounced by 450 ms and starts only after the first successful click on
  "Convert to SVG".
- The SVG preview is rendered from a `blob:` URL in an `<img>`, so no markup is injected and no
  script in the SVG can run.
- The download link is set after each conversion to `/api/svg/download/<id>?token=…&version=…&width=…&unit=…`.
  The UI always sends the width, so the downloaded file always has the current physical size.
- Preview: zoom with buttons, wheel or keys (`+`, `-`, `0`), pan by drag, double-click to fit. Two
  views (side by side, SVG only) and two SVG backgrounds (transparent checker, white).

## 4. Storage layout

```
storage/app/private/pictosvg/                 (not web-served; config pictosvg.storage_path)
└── <32-hex id>/                              (mode 0700)
    ├── meta.json        upload facts; its mtime sets the expiry clock
    ├── original.bin     the upload, mode 0600, never modified
    ├── analysis.json    transparency decision (created on first conversion)
    ├── bitmap-<key>.pbm prepared 1-bit bitmap; bitmaps untouched for 120 s are removed when a new one is written
    ├── result-<12 hex>.svg  one per conversion version; up to 5 kept
    ├── latest.json      {"version": "<12 hex>"}
    └── tmp-*            transient; removed after use or after 5 minutes
```

The root directory is outside `public/`. Nothing in it is reachable by URL.

## 5. Access model

- **Id:** 16 random bytes from `random_bytes`, encoded as 32 hex characters.
- **Token:** `HMAC-SHA256("pictosvg:" + id, APP_KEY)`, encoded as 64 hex characters.
- Both are required for every request after upload. The token is compared with `hash_equals`.
- Ids are validated against `^[a-f0-9]{32}$` before any path is built. User-supplied file names
  are never used in a path.
- Expiry: `meta.json` modification time plus `ttl_minutes` (default 60). The clock starts at upload
  and is **not** refreshed by later activity.
- Cleanup: `pictosvg:cleanup` runs every ten minutes through the Laravel scheduler. A 1-in-20 sweep
  also runs on upload. Only directories whose names match the id format are removed. Symbolic links
  are skipped.
- Rotating `APP_KEY` invalidates every token. Conversions in progress then return `invalid_token`.
- The download token is sent in the query string. It can therefore appear in web-server access logs
  and browser history. See [../docs/16-known-issues.md](16-known-issues.md).

Tests: `DownloadAndLifecycleTest::test_download_requires_the_matching_token`,
`DownloadAndLifecycleTest::test_tokens_depend_on_the_application_key`,
`DownloadAndLifecycleTest::test_expired_conversions_are_rejected_and_cleaned_up`,
`DownloadAndLifecycleTest::test_expired_conversion_is_rejected_even_before_cleanup_runs`.

## 6. External tools

`ExternalTool::run()`:
- Resolves the binary. A path containing `/` must be an executable file. A bare name is searched
  on `PATH` and in `/usr/local/bin`, `/opt/homebrew/bin` and `/usr/bin`. A missing binary logs the
  error server-side and returns `503 tool_missing`, with no path in the message.
- Runs with `Process::timeout(...)->path(sys_get_temp_dir())->run([binary, ...args])`. Arguments
  are an array, never a shell string.
- A timeout returns `504 trace_timeout`. Stderr containing "time limit exceeded" is treated the same.
- Other failures are logged, and the caller maps them to a generic `conversion_failed` or
  `unsupported_image`.

ImageMagick adds `-limit` flags (memory, map, disk, threads and time). Potrace gets only the
process timeout.

## 7. Security measures

| Risk | Measure |
|---|---|
| Malicious or disguised upload | Content checked with `finfo` and `getimagesize`. Extensions are ignored. Non-images return `unsupported_image`. |
| Oversized input | 20 MB byte limit and 40 M decoded-pixel limit. |
| Path traversal | Ids are format-checked. File names are never used. Versions must be 12 hex. |
| Command injection | Argument arrays only. No shell. |
| Resource exhaustion | Process timeout (default 40 s). ImageMagick memory, disk and thread limits. Working side capped at 3600 px. Raw trace capped at 3 MB; result capped at 15 MB. Rate limits per IP. |
| Unsafe SVG | Output is generated from numbers only. The served bytes pass an allow-list validator. Download carries `nosniff`, a sandboxing CSP and `no-store`. |
| XML attacks | `LIBXML_NONET`; DOCTYPE and ENTITY text rejected in the validator. |
| Information leaks | Errors are generic and contain no paths or command lines. Tests check this for a missing tracer. |
| Secrets | AI keys stay in server config. Not sent to the browser (the redraw endpoint returns no key). |
| Cross-user access | Id plus HMAC token. |
| Stored data | Private directory, mode 0700 for directories and 0600 for the original. Expiry and cleanup. |

## 8. Rate limits

Laravel `RateLimiter`, keyed by client IP, using the default cache store (`file` in `.env.example`):

| Bucket | Limit | Routes |
|---|---|---|
| `pictosvg-upload` | 20 per minute | `POST /api/images/upload`, `POST /api/ai/redraw` |
| `pictosvg-convert` | 180 per minute | `POST /api/svg/convert`, `POST /api/svg/optimize`, `GET /api/svg/download/{temporaryId}` |
| none | — | `GET /api/ai/status`, `GET /` |

Limits are per IP. They are the only concurrency bound. Client IPs come from the request. Laravel trusts
only `127.0.0.1` and `::1` as proxies (`bootstrap/app.php`), which matches an Nginx instance on the same
host. The test suite disables throttling.

## 9. Queues, sessions and database

- Queue connection is `sync`. No jobs are queued.
- Session and cache drivers are `file` in `.env.example`. The tests use `array`.
- No database is used. `DB_CONNECTION=null`. No models exist. `database/migrations` is empty.

## 10. Health check

`bootstrap/app.php` sets the Laravel default health route `/up`. It has not been checked in this
milestone.
