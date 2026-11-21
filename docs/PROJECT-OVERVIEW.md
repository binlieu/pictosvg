# PicToSVG Project Overview

**PicToSVG** — *Turn Pictures into Precision Vectors.*

PicToSVG is a web-based image-to-SVG converter for laser engraving, CNC carving and vector
cutting. A user uploads a JPG, PNG or WebP picture, clicks **Convert to SVG**, inspects the result
and downloads a standalone SVG sized in millimetres or inches.

Status as of 2026-11-21: MVP implemented. See [14-current-status.md](14-current-status.md).

## What it does

1. **Upload** a picture by drag and drop, file picker or paste (JPG/JPEG, PNG, WebP; up to 20 MB).
2. **Convert** with one of three presets: **Engrave** (default), **Outline Cut** or **Line Art**.
   Optimise Smooth Curves is on by default.
3. **Preview** the original and the SVG side by side. Zoom, pan, fit, and switch between side by
   side and SVG-only views. Check the path and node counts, the output size and any warnings.
4. **Adjust** with three sliders by default (Detail 7, Smoothing 8, Noise Removal 5) or in
   Advanced Settings (threshold, contrast, invert, curve tolerance, speck size, corner preservation,
   outline-only output, transparent background).
5. **Download** the SVG. Width and unit can be changed without re-tracing.

## What it is not

- Not a native desktop app, Electron shell or single-page application.
- Not a vector editor.
- Not a G-code generator or a cut-safety validator.
- Not a multi-user service. No accounts, billing, history or cloud storage.
- Not dependent on AI. The optional AI redraw feature is gated and not implemented.

## Stack

| Part | Choice |
|---|---|
| Backend | Laravel 13 on PHP 8.3 (`laravel/framework` v13.43.0 in `composer.lock`) |
| UI | Laravel Blade, Bootstrap 5.3.3 (vendored), vanilla JavaScript, one stylesheet |
| Image preparation | ImageMagick 7 CLI (`magick`), called through the Laravel Process facade |
| Tracing | Potrace 1.16 CLI (GPL-2.0-or-later, run as a separate program) |
| Storage | Private temporary directories. No database. |
| Target deployment | Ubuntu, Nginx, PHP-FPM (instructions written; not yet tested on Ubuntu) |

## Key decisions

- [ADR 0001](adr/0001-potrace-as-tracing-engine.md): Potrace is the tracing engine. VTracer is not used.
- [ADR 0002](adr/0002-stateless-token-access-no-database.md): stateless id and HMAC token access. No database.
- [ADR 0003](adr/0003-imagemagick-cli-instead-of-imagick-extension.md): ImageMagick is called as a CLI, not through the PHP extension.
- [ADR 0004](adr/0004-ai-redraw-gated-not-implemented.md): AI redraw is gated and not implemented.

## Where to read next

- [MASTER-SPEC.md](MASTER-SPEC.md): product requirements with a status for each.
- [ARCHITECTURE.md](ARCHITECTURE.md): request flow, services, storage and security.
- [FEATURES.md](FEATURES.md): feature IDs and verification levels.
- [IMAGE-PROCESSING.md](IMAGE-PROCESSING.md) and [SVG-OPTIMIZATION.md](SVG-OPTIMIZATION.md): the algorithms and their settings.
- [API.md](API.md): HTTP endpoints.
- [INSTALLATION.md](INSTALLATION.md), [TESTING.md](TESTING.md), [FUTURE-ENHANCEMENTS.md](FUTURE-ENHANCEMENTS.md).
- [16-known-issues.md](16-known-issues.md) and [15-agent-handoff.md](15-agent-handoff.md).
