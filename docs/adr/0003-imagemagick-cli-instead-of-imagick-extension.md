# ADR 0003: ImageMagick CLI instead of the imagick extension

Status: Accepted (2026-11-21)

## Context

Raster preparation needs decoding, orientation, flattening, resize, contrast, blur and thresholding.
PHP can use the `imagick` extension or the ImageMagick command-line program.

## Decision

Call the ImageMagick 7 `magick` binary through the Laravel Process facade. Pass an argument array,
a timeout and `-limit` resource flags. Do not use the PHP `imagick` extension.

PHP's GD is not used by the application. It appears only in one test (`ConversionTest::test_blank_image_gives_an_actionable_error`).

## Consequences

- A process per operation. Each `prepareBitmap` call costs a process start. The conversion takes
  about 0.6–1.5 s on the development machine.
- Limits are explicit and enforced by ImageMagick (`-limit memory`, `map`, `disk`, `thread`, `time`).
- The binary path is configurable (`PICTOSVG_MAGICK_BIN`). Ubuntu's default ImageMagick 6 provides
  `convert` rather than `magick`. Its compatibility with these arguments is **not verified**.
- Behaviour depends on the installed ImageMagick build. Operators such as `-auto-threshold` must
  exist in the version deployed. The development version is ImageMagick 7.
- No `imagick` extension is required on the server.
