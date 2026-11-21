# PRE-001: Raster preprocessing

Status: Implemented. Verification: Automated Tested (through conversions); Visual Reviewed (fixtures).

## Scope

Turn the stored upload into a one-bit bitmap for tracing: orientation, transparency, grey,
working size, contrast, edge smoothing, invert and threshold. Only ImageMagick is used. The original
upload is never modified.

## Behaviour

Full details, formulas and the exact ImageMagick sequence are in [IMAGE-PROCESSING.md](../IMAGE-PROCESSING.md).

- Orientation: `-auto-orient`.
- Transparency: opaque images and most images are flattened onto white. Light artwork on transparency
  uses its opaque shape, and a warning says so.
- Working size from Detail (`500 + 250·d`, capped at 3600 px), with enlargement limited to 1.6–2.5×.
- Edge smoothing: Gaussian blur whose sigma depends on Smoothing and on the enlargement.
- Contrast: auto-level and Image contrast.
- Threshold: Auto (OTSU) or a manual percentage. Invert after blur.
- Output: 1-bit PBM, cached per preparation settings.
- Resource limits: ImageMagick `-limit` memory, map, disk, threads and time.

## Acceptance criteria

1. A transparent PNG with a hole keeps the hole, and the background is not filled.
2. A light-on-transparent image traces its visible shape and gets a warning.
3. A rotated JPEG is traced upright.
4. Invert, contrast and threshold change the result as documented.
5. A blank image gives an actionable error, not a crash.
6. Preparation runs once per settings set. Changing Noise Removal does not re-run ImageMagick.

## Tests

- `tests/Feature/ConversionTest.php`:
  - `test_transparent_png_is_flattened_onto_white_and_keeps_its_hole`
  - `test_light_artwork_on_transparent_background_traces_the_visible_shape`
  - `test_rotated_jpeg_is_traced_upright`
  - `test_advanced_settings_invert_units_and_background`
  - `test_blank_image_gives_an_actionable_error`
- Bitmap caching is checked indirectly by `DownloadAndLifecycleTest::test_only_a_bounded_number_of_result_versions_is_kept`,
  which asserts one `bitmap-*.pbm` after eight conversions of one image.

## Limitations

- Grayscale only. No colour.
- No raster despeckle filter. Speck removal happens in Potrace and after tracing.
- EXIF orientation depends on the `exif` extension. Without it the bitmap may be distorted for
  rotated JPEGs. See [16-known-issues.md](../16-known-issues.md).
- Photographs are approximate.
- The bitmap cache key does not include the output width, so changing width is cheap. Changing
  Detail or Smoothing re-runs ImageMagick.
