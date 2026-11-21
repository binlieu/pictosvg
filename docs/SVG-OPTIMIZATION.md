# SVG Optimisation and Export

Status: Implemented (MVP). Source of truth: `app/Services/Tracing/VectorTracingService.php`,
`app/Services/Svg/SvgOptimizationService.php`, `app/Services/Svg/PathGeometry.php`,
`app/Services/Svg/SvgExportService.php` and `app/Services/Svg/SvgValidationService.php`.

Formulas for the Potrace settings (`--turdsize`, `--alphamax`, `--opttolerance`) are in
[IMAGE-PROCESSING.md](IMAGE-PROCESSING.md) §2 and are not repeated here.

## 1. Division of work

| Stage | Who | What it does |
|---|---|---|
| Curve fitting | Potrace (`--alphamax`, `--opttolerance`) | Fits cubic Bézier curves to the bitmap outline and joins them within the optimisation tolerance. |
| Speck removal (Potrace) | Potrace (`--turdsize`) | Drops traced regions below the speck area. |
| Speck removal (post-trace) | `SvgOptimizationService` | Drops shapes whose outer contour is below `min_area`. Drops holes below `min_area` on their own. |
| Flat-curve straightening | `SvgOptimizationService` | A cubic whose control points lie on its chord becomes a line segment. |
| Straight-run merging | `SvgOptimizationService` | Removes nodes that lie on a straight run of line segments. |
| Duplicate removal | `SvgOptimizationService` | Removes an exact duplicate contour (same geometry at 1 decimal place). |
| Serialisation | `SvgExportService` | Writes compact relative path data with 1 decimal place. |

The raw trace (`--longcurve`, no curve optimisation) and the curve-fitted trace are kept
separate. The raw trace is used to report `nodes_before` and is the output when Smoothing is off.

## 2. SvgOptimizationService

Called with `tolerance = simplifyTolerance(longWorkingSide)` and `min_area = speckArea`.

### 2.1 Speck and hole removal

For each shape (one `<path>`):
- A subpath with `|signedArea| < min_area` is removed.
- If the removed subpath is the outer contour, the whole shape is removed (holes are necessarily
  smaller than their outer contour).
- A removed hole is dropped alone.

Real holes larger than `min_area` are preserved. The winding of the outer contour and its holes is
never changed.

### 2.2 Flat-curve straightening

A cubic segment becomes a line when both control points lie within `tolerance × 0.25` of the
chord. The stricter factor keeps real curves as curves.

### 2.3 Straight-run merging

Consecutive line endpoints form a run. Greedily, a run is replaced by a longer straight segment as
long as every skipped node lies within `tolerance` of the new segment and projects inside it. The look-ahead window is capped at 48 points per step, so a very long straight run is merged in bounded time.
The closing edge of a closed subpath is made explicit first, so nodes on it can also merge.

Sharp corners are kept because they lie far from the chord of any longer segment.

### 2.4 Duplicate removal

Each subpath is serialised at precision 1 and hashed with MD5. An exact repeat is removed. If the
repeat is the outer contour of a shape, the shape is removed.

### 2.5 Explicitly not done

- No merging of shapes, no union or boolean operations, no overlap cleanup.
- No reordering of subpaths within a shape and no change to winding or fill rule.
- No intersection detection.
- No curve re-fitting beyond what Potrace produces.
- No speck removal on raster input; that is done by Potrace and the post-trace `min_area`.
- No G-code and no cut-safety guarantee.

## 3. Measured results (default settings, synthetic fixtures)

Measured on the developer machine with the default settings (Engrave, Detail 7, Smoothing 8,
Noise Removal 5, Optimize Smooth Curves on). Columns: paths, nodes after optimisation, nodes of
the raw trace (`nodes_before`).

| Fixture | Paths | Nodes after | Nodes raw |
|---|---|---|---|
| `logo.png` | 3 | 31 | 149 |
| `transparent-logo.png` | 2 | 25 | 188 |
| `text.png` | 110 | 1169 | 2295 |
| `line-drawing.png` (Line Art) | 10 | 185 | 778 |
| `vehicle-photo.jpg` | 1 | 71 | 178 |
| `illustration.png` | 6 | 127 | 493 |

Conversion time on the developer machine was 0.6–1.5 s per image. This is an observation from the
development environment, not a benchmark.

These are one-off measurements. They are not regression thresholds. The automated tests assert
looser bounds (for example `ConversionTest::test_logo_converts_with_defaults_to_a_real_sized_vector`
asserts fewer than 60 nodes).

Path and node counts are measured from the validated exported document:
- `paths` counts `<path>` elements. Each element is one shape, with an outer contour and any holes.
- `nodes` = `PathGeometry::nodeCount` per subpath: one plus the segment count, minus one when the
  closing segment ends at the start point.

## 4. Export format (SvgExportService)

Output for an Engrave conversion (values depend on the request):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="150mm" height="100mm" viewBox="0 0 1320 880">
<g fill="#000000" fill-rule="nonzero" stroke="none">
<path d="…"/>
</g>
</svg>
```

Rules:
- `width` and `height` are a positive number with unit `mm` or `in`. Height = width × viewBox
  height / viewBox width, formatted with up to 4 decimals and trailing zeros removed.
- `viewBox` is `0 0 <working width> <working height>` (integer pixels of the working bitmap).
- Path data is absolute start point followed by relative commands (`M`, `l`, `c`, `z`), derived
  from rounded absolute positions so rounding error does not accumulate. Precision is 1 decimal
  place in viewBox units.
- No `<image>`, no `<text>`, no metadata, no `transform` attributes, no scripts and no styles.
- A white `<rect>` is added only when "Transparent background" is off and the preset is not Outline.
- **Outline Cut:** `<g fill="none" stroke="#000000" stroke-width="S" stroke-linejoin="round">`,
  with `S = max(width, height) / 600` rounded to 2 decimals (2.2 for the default logo, about
  0.17 % of the drawing size). The stroke is a hairline; laser and CNC software usually ignores
  stroke width. This is the author's intent and has not been checked in any importer.

### Dimensioning without re-tracing

`SvgExportService::withDimensions()` rewrites only the root `width` and `height` attributes. The
`viewBox` and all `<path>` data stay byte-identical. Tests confirm that a resized download differs
from the preview only in those two attributes (`DownloadAndLifecycleTest::test_download_can_be_resized_without_changing_geometry`,
`SvgExportAndValidationTest::test_redimensioning_changes_only_root_width_and_height`).

## 5. Validation (SvgValidationService)

The exact bytes that are served are validated. The validator rejects the document unless all of
the following hold.

| Check | Rule |
|---|---|
| Declarations | No `<!DOCTYPE` or `<!ENTITY` text. |
| Well-formed XML | Loaded with `LIBXML_NONET`. |
| Root | `svg` in the SVG namespace. |
| Elements | Allow-list: `svg`, `g`, `path`, `rect` only. Every element must be in the SVG namespace. |
| Attributes | `svg`: `xmlns`, `version`, `width`, `height`, `viewBox`. `g`: `fill`, `fill-rule`, `stroke`, `stroke-width`, `stroke-linejoin`. `path`: `d`. `rect`: `width`, `height`, `fill`. |
| Attribute values | Reject `url(`, `javascript:`, `data:` and `&` anywhere in a value. |
| viewBox | `0 0 <int> <int>`, both positive. |
| width / height | `<number>mm` or `<number>in`, the same unit for both, both positive. |
| Aspect ratio | Stated height matches width × viewBox ratio within `max(0.001, 0.1 %)`. |
| Path data | Only `M m L l C c Z z`. Any other command (for example `A` arcs) is rejected. |
| Content | At least one `<path>`, and no path with an empty subpath list. |

The validator also returns the counts used in the API stats (paths, subpaths, nodes, open
subpaths, sizes and unit).

Open subpaths are counted, not rejected. Outline Cut reports them as a warning.

Tests: `SvgExportAndValidationTest::test_validator_rejects_unsafe_or_malformed_documents` covers
script, image, event handler, `foreignObject`, DOCTYPE with entity, style attribute, wrong aspect
ratio, missing unit, no paths, not XML and arc command.

## 6. Limits

- `max_svg_bytes` = 15 MB. A larger result fails with "too complex" (`conversion_failed`).
- Raw Potrace output larger than 3 MB (`pictosvg.max_trace_bytes`) is refused before parsing, with a "too complex" message, so a very noisy image cannot exhaust PHP memory.
- More than 1500 `<path>` elements adds a warning about approximate results.

## 7. What is not verified

- Importer behaviour in LightBurn, Vectric Aspire / VCarve and Inkscape. Not Verified: none of
  those applications is installed.
- Machine (laser or CNC) behaviour. Not Verified.
- Whether `stroke-width` in Outline Cut is handled as intended by any cutting software.
