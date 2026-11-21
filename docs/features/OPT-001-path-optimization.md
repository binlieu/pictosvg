# OPT-001: Path optimisation

Status: Implemented, partially against the master spec (overlap cleanup is not implemented).
Verification: Automated Tested; Visual Reviewed (fixtures).

## Scope

Reduce nodes and remove artefacts after tracing, while keeping corners, holes and fill behaviour.
Implemented in `SvgOptimizationService` and driven by `ConversionPipeline`. The raw trace is kept
for comparison.

## Behaviour

Details and tolerances are in [SVG-OPTIMIZATION.md](../SVG-OPTIMIZATION.md) §2.

- Speck removal: subpaths below `min_area` are dropped. An outer contour removes its whole shape.
- Flat curves: a cubic whose control points lie within `tolerance × 0.25` of its chord becomes a line.
- Straight-run merge: nodes within `tolerance` of a straight run are removed. Corners far from the
  chord stay.
- Duplicate removal: an exact duplicate contour (at precision 1) is dropped.
- Off switch: "Optimize Smooth Curves" off skips the optimiser and uses the raw trace.
- `nodes_before` reports the raw trace's node count, so the UI can show "was N before optimization".

## Acceptance criteria

1. A wobbly square becomes four corner nodes with the same area.
2. A 3-unit step is kept as geometry at tolerance 1.0.
3. Real curves stay curves. Flat curves become lines.
4. Tiny outer shapes and tiny holes are removed. A real hole stays. Winding signs are preserved.
5. Exact duplicate contours are removed. Near-duplicates are kept.
6. Zero tolerance changes nothing.
7. On `illustration.png`, optimisation removes at least 20 % of the raw nodes and keeps the fill
   equal on the sample grid (at most two sample points differ).
8. Higher Smoothing never increases the node count on `text.png`.

## Tests

- `tests/Unit/SvgOptimizationServiceTest.php`:
  - `test_removes_nodes_on_straight_runs_but_keeps_corners`
  - `test_does_not_cut_a_real_corner_even_with_generous_tolerance`
  - `test_real_curves_stay_curves_and_flat_curves_become_lines`
  - `test_removes_tiny_specks_and_tiny_holes_but_preserves_real_holes`
  - `test_removes_exact_duplicate_contours_only`
  - `test_long_straight_runs_are_simplified_in_bounded_time`
  - `test_zero_tolerance_changes_nothing`
- `tests/Feature/ConversionTest.php`:
  - `test_optimisation_reduces_measured_nodes_without_changing_the_shape`
  - `test_higher_smoothing_never_increases_nodes_and_keeps_text_legible_shapes`
  - `test_optimize_endpoint_requires_a_previous_conversion`
  - `test_noise_removal_drops_specks_and_zero_keeps_them`

## Measured results

See [SVG-OPTIMIZATION.md](../SVG-OPTIMIZATION.md) §3 for the fixture table (paths, nodes after,
nodes raw).

## Limitations

- Overlapping paths are not merged. This is deliberate: merging can change holes and cut behaviour.
- No intersection detection.
- Duplicate detection is exact, not geometric.
- Precision is fixed at one decimal place in the controller path.
- The optimiser runs on the traced geometry only. It does not re-fit curves. The straight-run look-ahead is capped at 48 points.
