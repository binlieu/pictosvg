# EXPORT-001: SVG output

Status: Implemented. Verification: Automated Tested; Visual Reviewed (fixtures); Importer Verified:
Not Verified; Machine Verified: Not Verified.

## Scope

Build the standalone SVG from numeric geometry, size it in millimetres or inches, validate the exact
bytes, serve them safely, and re-size a result without re-tracing. Covers `SvgExportService`,
`SvgValidationService`, the download endpoint and the width controls.

## Behaviour

Full format rules are in [SVG-OPTIMIZATION.md](../SVG-OPTIMIZATION.md) §4 and §5.

- Root: `width` and `height` in `mm` or `in`, `viewBox="0 0 W H"` in working-bitmap pixels.
- Height = width × H / W, up to four decimals.
- Paths: one `<path>` per shape, relative commands, one decimal place.
- Group: `fill="#000000" fill-rule="nonzero" stroke="none"`. Outline Cut uses `fill="none"` with a
  hairline stroke. A white rectangle is added only when transparent background is off.
- Validation before storage and again before download. The allow-list covers elements, attributes and
  values.
- Download: `GET /api/svg/download/{id}?token=&version=&width=&unit=`. Adds `Content-Disposition:
  attachment`, `nosniff`, a sandboxing CSP and `no-store`. The file name is `<stem>-pictosvg.svg`,
  with the stem sanitised to `[A-Za-z0-9_-]`.
- Re-sizing with `width` and `unit` rewrites only the root `width` and `height` attributes.

## Acceptance criteria

1. A 150 mm default export has `width="150mm"`, the matching height and the working-pixel viewBox.
2. Inches and non-round aspect ratios are formatted correctly and validate.
3. Re-sizing changes only `width` and `height`. The rest of the document is byte-identical.
4. Outline mode uses a stroke and adds no background rectangle.
5. The export contains no `<image>`, `xlink`, `href`, metadata, transforms, scripts, styles,
   `base64` or DOCTYPE.
6. The validator rejects scripts, images, event handlers, `foreignObject`, entity declarations,
   `style` attributes, a wrong aspect ratio, a missing unit, no paths, non-XML input and arc commands.
7. The download is byte-identical to the previewed SVG when no width is given.
8. The download token is required, and the wrong token gets `403`.

## Tests

- `tests/Unit/SvgExportAndValidationTest.php`:
  - `test_150mm_export_has_matching_height_and_viewbox`
  - `test_inches_and_non_round_aspect_ratio`
  - `test_redimensioning_changes_only_root_width_and_height`
  - `test_outline_mode_uses_stroke_and_never_adds_a_background_rectangle`
  - `test_export_contains_no_raster_metadata_or_transforms`
  - `test_validator_rejects_unsafe_or_malformed_documents`
- `tests/Feature/DownloadAndLifecycleTest.php`:
  - `test_download_serves_the_exact_previewed_svg_as_a_safe_attachment`
  - `test_download_can_be_resized_without_changing_geometry`
  - `test_download_requires_the_matching_token`
  - `test_download_before_conversion_is_not_found`
- `tests/Feature/ConversionTest.php::test_advanced_settings_invert_units_and_background` (inch sizing
  and white background).

## Limitations

- Importer compatibility (LightBurn, VCarve/Aspire, Inkscape) is not verified.
- The outline stroke width is a hairline the author chose. Its effect in cutting software is not known.
- The UI always sends a width on download. The downloaded file therefore always carries the rewritten
  `width` and `height`, although the geometry is the same.
- Arc commands are not supported. Tracer output does not use them.
- Dimensions are in working-bitmap pixels for the viewBox. Physical size is set only by `width`.
