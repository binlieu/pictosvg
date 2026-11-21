# PicToSVG

**Turn Pictures into Precision Vectors.**

PicToSVG is a small web app that converts JPG, PNG and WebP pictures into clean, smooth,
physically sized SVG files for laser engraving, CNC carving and vector cutting.

**Upload image → Convert to SVG → Preview and adjust → Download SVG.**
No account, no database, no API key.

- Real vector paths (cubic Béziers traced by [Potrace](https://potrace.sourceforge.net/)) — never a
  picture embedded in an SVG.
- Three presets: **Engrave** (filled black-and-white), **Outline Cut** (closed contours),
  **Line Art** (traces drawn lines as outlined shapes — not single-stroke centerlines).
- Three plain sliders — Detail, Smoothing, Noise Removal — plus optional Advanced Settings.
- Output width in **millimeters or inches**; height follows the picture's aspect ratio.
- Side-by-side preview with zoom/pan, path and node counts measured from the real file.

Built with Laravel 13, Blade, Bootstrap 5 and vanilla JavaScript. Image work is done by the
ImageMagick and Potrace command-line tools.

## Requirements

| Tool | Version used in development | Notes |
|---|---|---|
| PHP | 8.3 | extensions: `fileinfo`, `gd`, `exif`, `dom`, `mbstring` |
| Composer | 2.x | |
| ImageMagick | 7.x (`magick` command) | CLI only; the PHP `imagick` extension is not needed |
| Potrace | 1.16 | GPL; called as a separate program |

Node.js is **not** required — there is no build step.

## Quick start (macOS)

```bash
brew install php composer imagemagick potrace
```

```bash
git clone https://github.com/binlieu/pictosvg.git && cd pictosvg
```

```bash
composer install
```

```bash
cp .env.example .env && php artisan key:generate
```

```bash
php artisan serve
```

Open <http://127.0.0.1:8000>, drop in a picture, click **Convert to SVG**, then **Download SVG**.

If PHP cannot find the tools (common under PHP-FPM, which has a minimal `PATH`), set absolute
paths in `.env`:

```dotenv
PICTOSVG_MAGICK_BIN=/opt/homebrew/bin/magick
PICTOSVG_POTRACE_BIN=/opt/homebrew/bin/potrace
```

## Quick start (Ubuntu)

```bash
sudo apt install php-cli php-fpm php-xml php-mbstring php-gd php-curl unzip imagemagick potrace
```

Then follow the same `composer install` / `.env` / `key:generate` steps. Ubuntu's packaged
ImageMagick 6 provides `convert` instead of `magick`; PicToSVG was developed against
ImageMagick 7, so install v7 and point `PICTOSVG_MAGICK_BIN` at its `magick` binary.
Full Nginx + PHP-FPM instructions are in [docs/INSTALLATION.md](docs/INSTALLATION.md)
(written but **not yet exercised on Ubuntu**).

Production checklist:

- `upload_max_filesize` and `post_max_size` (php.ini) and `client_max_body_size` (Nginx) ≥ 20 MB.
- `storage/` writable by the PHP user. Uploads live in `storage/app/private/pictosvg` and are
  never web-accessible.
- Add the scheduler cron so expired uploads are deleted (default lifetime 60 minutes):
  `* * * * * cd /path/to/pictosvg && php artisan schedule:run >> /dev/null 2>&1`
- `APP_ENV=production`, `APP_DEBUG=false`.

## Configuration (`.env`)

| Key | Default | Meaning |
|---|---|---|
| `PICTOSVG_POTRACE_BIN` | `potrace` | Potrace binary (name or absolute path) |
| `PICTOSVG_MAGICK_BIN` | `magick` | ImageMagick 7 binary |
| `PICTOSVG_MAX_UPLOAD_MB` | `20` | Upload size limit |
| `PICTOSVG_MAX_PIXELS` | `40000000` | Decoded pixel limit (width × height) |
| `PICTOSVG_TTL_MINUTES` | `60` | How long uploads and results are kept |
| `PICTOSVG_PROCESS_TIMEOUT` | `40` | Seconds allowed per external tool run |
| `PICTOSVG_AI_PROVIDER`, `PICTOSVG_AI_API_KEY` | empty | Reserved for optional AI Redraw (see below) |

## Using it

1. **Upload** — drag and drop, browse, or paste from the clipboard.
2. **Convert** — pick a preset and click **Convert to SVG**. Afterwards, moving a slider
   re-converts automatically.
3. **Download** — set the output width (mm or inches) and click **Download SVG**. Changing the
   size never re-traces or distorts the artwork; only the SVG's `width`/`height` change.

Tips: for photos, raise **Noise Removal** and lower **Detail** — photographs convert only
approximately. For white-on-dark artwork use **Invert** in Advanced Settings.

## Tests and quality gate

```bash
php artisan test
```

```bash
./scripts/check.sh
```

The suite runs **real conversions** (ImageMagick + Potrace must be installed) on the fixtures
in `tests/fixtures` and checks SVG validity, physical dimensions, aspect ratio, hole
preservation, corner preservation, noise clean-up, closed cut contours, download
authorization, expiry/cleanup and rejection of invalid or malicious uploads.
`./scripts/make-fixtures.sh` regenerates the (synthetic) fixtures.

## What is and is not verified

| Level | Status |
|---|---|
| Automated tests | Passing (see `docs/TESTING.md`) |
| Visual review of fixture conversions | Done during development |
| Import into LightBurn / VCarve / Aspire / Inkscape | **Not verified** |
| Real laser or CNC output | **Not verified** |
| Real photographs | **Not tested** (the photo fixture is a generated scene) |

PicToSVG creates vector files; it does not generate G-code and cannot guarantee a design is
safe to cut. Always check the file in your laser/CNC software first.

## Not implemented (yet)

- **AI Redraw** — only the switch exists; no AI provider is integrated, so the button stays
  disabled. Normal conversion never depends on it.
- True centerline (single-stroke) tracing, colour tracing, VTracer, intersection warnings for
  cut paths, background queues.

## Documentation

See [`docs/`](docs): [API](docs/API.md), [Architecture](docs/ARCHITECTURE.md),
[Image processing](docs/IMAGE-PROCESSING.md), [SVG optimization](docs/SVG-OPTIMIZATION.md),
[Installation](docs/INSTALLATION.md), [Testing](docs/TESTING.md),
[Known issues](docs/16-known-issues.md).

## License

PicToSVG is licensed under the [Apache License 2.0](LICENSE). Copyright 2026 ChiLT.

Third-party tools keep their own licenses: Potrace (GPL) and ImageMagick are used as external
command-line programs and are not bundled with this repository. Bootstrap (MIT) is vendored in
`public/vendor/bootstrap`; the Laravel framework (MIT) is installed by Composer.
