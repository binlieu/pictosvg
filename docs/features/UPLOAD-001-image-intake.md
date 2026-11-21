# UPLOAD-001: Image intake

Status: Implemented. Verification: Automated Tested (API). Browser interaction: Not Verified.

## Scope

Accept one JPG/JPEG, PNG or WebP image from the browser (drag and drop, file picker or paste),
validate it by content, store the original privately, and show the thumbnail, name and dimensions.
Change and remove are client-side.

## Behaviour

- `POST /api/images/upload`, field `image`, `multipart/form-data`. Returns `201` with `id`, `token`,
  `name`, `width`, `height`, `bytes`, `mime` and `expires_in`.
- Byte limit: `max_upload_mb` (20). Larger files return `image_too_large`. The browser also checks
  the size and type before uploading.
- Content check: `finfo` MIME must be one of JPEG, PNG or WebP, and `getimagesize` must agree. The
  file name and extension are ignored.
- Minimum 8 × 8 px. Decoded pixels at most `max_pixels` (40 M). Otherwise `image_too_large` with the
  megapixel figures in the message.
- Orientation metadata (EXIF in JPEG, PNG and WebP) is read with ImageMagick (`%[orientation]`). Orientations 5–8 swap the reported width and height. This does not depend on PHP's `exif` extension.
- Display name: basename, characters outside letters, digits, space and `._()-` replaced by `_`,
  120 characters at most. Never used as a path. Fallback `image`.
- Client side: accepts `image/jpeg`, `image/png`, `image/webp`, or a `.jpg`, `.jpeg`, `.png` or
  `.webp` name when the browser gives no type. Empty files are refused. The upload shows progress.
- Paste: a clipboard image is uploaded. Drops outside the drop zone do not navigate the browser.
- Change replaces the image. Remove clears the image and the current result.

## Acceptance criteria

1. PNG, JPEG and WebP are accepted, with the right dimensions, mime and stored original.
2. Rotated JPEG reports orientation-corrected dimensions.
3. Non-images are rejected whatever their extension. Nothing is stored for a rejected upload.
4. Missing file returns `unsupported_image`.
5. Pixel and byte limits are enforced.
6. A client file name containing path characters is never used as a path, and the stored directory
   holds only `meta.json` and `original.bin`.

## Tests

- `tests/Feature/UploadTest.php`:
  - `test_accepts_png_jpeg_and_webp_and_reports_dimensions`
  - `test_reports_orientation_corrected_dimensions`
  - `test_png_orientation_metadata_is_honoured_like_jpeg`
  - `test_rejects_non_images_regardless_of_extension`
  - `test_rejects_missing_file`
  - `test_enforces_decoded_pixel_limit_and_byte_limit`
  - `test_client_filename_is_never_used_as_a_path`
- `tests/Feature/ConversionTest.php::test_blank_image_gives_an_actionable_error` covers an upload
  that converts to nothing.

## Limitations

- No browser tests exist for drag and drop or paste.
- The 20 MB boundary is not tested with a real large file.
- If PHP's `upload_max_filesize` or Nginx's `client_max_body_size` is below 20 MB, uploads fail
  before the application sees them (see [INSTALLATION.md](../INSTALLATION.md)).
- No HEIC, TIFF, GIF or SVG input. This is intentional.
