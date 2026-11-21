# TRACE-001: Vector conversion

Status: Implemented. Verification: Automated Tested; Visual Reviewed (fixtures); Importer Verified:
Not Verified; Machine Verified: Not Verified.

## Scope

Trace the prepared bitmap into real Bézier geometry with Potrace 1.16. Provide the three presets
(Engrave, Outline Cut, Line Art), keep holes and corners, and report warnings. Tracing is separate
from post-trace optimisation (OPT-001) and from export (EXPORT-001).

## Behaviour

- `POST /api/svg/convert` and `POST /api/svg/optimize` run `ConversionPipeline::convert`.
- Potrace is called with `--svg --turdsize --alphamax --unit 10` plus `--longcurve` (raw) or
  `--opttolerance` (curve-fitted). Its SVG is read back with DOM and the transforms are baked in.
- Presets:
  - **Engrave** (default): filled shapes, non-zero fill rule.
  - **Outline Cut**: closed contours as hairline strokes. The speck factor is 2×. Warnings for open
    contours and for both-edge tracing are always added.
  - **Line Art**: filled shapes outlining each line. The working size is 1.25× larger, blur is 0.75×
    and the speck factor is 0.5×. A warning says it is not a centerline.
- Holes are kept (subpaths after the outer contour in the same `<path>`).
- Corners follow `--alphamax`, or Corner preservation when set.
- "Nothing was found to trace" when nothing remains after speck removal.
- Result warnings: alpha-shape notice, open outlines, both-edge notice, Line Art notice, a note for
  more than 1500 paths.

## Acceptance criteria

1. The logo converts to 3 paths and 4 subpaths, with the ring hole present, and 150 mm × 100 mm
   output for the default width.
2. Points inside the ring body, square, triangle and hole are filled or empty as expected.
3. The square's corners stay sharp (3 px inside filled, 3 px outside empty).
4. Outline Cut output has only closed, non-duplicated contours and warnings.
5. Line Art traces thin strokes and its warning says it does not produce centerlines.
6. A photo-like image produces a bounded, approximate result (fewer than 400 paths).
7. Missing Potrace returns `tool_missing` (503), and the message contains no path.

## Tests

- `tests/Feature/ConversionTest.php`:
  - `test_logo_converts_with_defaults_to_a_real_sized_vector`
  - `test_holes_and_shapes_are_preserved`
  - `test_sharp_corners_survive_default_smoothing`
  - `test_outline_cut_produces_only_closed_stroked_contours`
  - `test_line_art_traces_thin_strokes_and_says_it_is_not_centerline`
  - `test_photo_like_image_converts_to_an_approximate_vector`
  - `test_missing_tracer_binary_is_reported_without_leaking_paths`
  - `test_optimize_endpoint_requires_a_previous_conversion`
- `tests/Unit/PathGeometryTest.php` (6 methods) covers the parser used on Potrace output, including
  `test_bakes_potrace_flip_transform_into_coordinates`.

## Limitations

- Potrace only. VTracer is not integrated.
- No centerline tracing. No colour tracing.
- Outline Cut traces both edges of a drawn line, so each line produces two cut lines. The warning
  says so.
- Line Art outlines lines, which is not the same as a single stroke.
- The photograph test uses a synthetic image.
- No intersection detection. Overlapping outlines are not reported.
- Importer and machine behaviour: Not Verified.
