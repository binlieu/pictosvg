# Image Processing and Tracing Settings

Status: Implemented (MVP). Source of truth: `app/Services/ConversionSettings.php`,
`app/Services/ImageProcessing/ImagePreprocessingService.php`,
`app/Services/Tracing/VectorTracingService.php` and `app/Services/ConversionPipeline.php`.

This document describes exactly what the code does. Every formula below is taken from
`ConversionSettings.php`; the worked numbers were computed from those formulas for the
settings shown, not measured separately.

## 1. Pipeline overview

```
upload (original.bin, never modified)
  └─ analyseTransparency()           once per upload, cached in analysis.json
  └─ prepareBitmap()  (ImageMagick)  cached as bitmap-<key>.pbm
  └─ Potrace  --longcurve            raw trace   → nodes_before (and the output when Smoothing is off)
  └─ Potrace  --opttolerance         curve-fitted trace
  └─ SvgOptimizationService          speck removal, straightening, node merge, duplicate removal
  └─ SvgExportService                standalone SVG in physical units
  └─ SvgValidationService            allow-list + geometry checks on the exact bytes served
```

Potrace runs **twice** per conversion when "Optimize Smooth Curves" is on (raw and curve-fitted).
When it is off, only the raw trace is produced and the optimiser is skipped.

## 2. Mapping plain controls to engine settings

Inputs: `preset` (`engrave` | `outline` | `lineart`), `detail` (1–10, default 7),
`smoothing` (0–10, default 8), `noise` (0–10, default 5), plus advanced fields.
Let `T(d) = 500 + 250·d` be the target long side in working pixels (see §3).

### 2.1 Working size

- `target = 500 + 250·detail`; for `lineart`, `target = round(target × 1.25)`.
- `target = min(target, 3600)` (`max_working_side`).
- `maxUpscale = 1.5 + 0.1·detail` (1.6× at Detail 1 … 2.5× at Detail 10).
- `scale = min(target / longSourceSide, maxUpscale)`.
- `width = max(8, round(sourceWidth × scale))`, likewise for height.

Downscaling happens when `target / longSourceSide < 1` and the image is larger than the target.
Enlargement is capped by `maxUpscale`, not by `target`, so small images get at most 1.6–2.5×.

Why the cap: the enlargement cap was chosen from measurements on `logo.png`. Enlarging by 4× gave
roughly 5–8× more path coordinates than 2× at comparable blur, with wobbling edges. Larger
enlargement adds stair-steps and nodes, not real detail.

Worked values for a 600 × 400 source:

| Detail | target long side | maxUpscale | scale | working size |
|---|---|---|---|---|
| 1 | 750 | 1.6 | 1.25 | 750 × 500 |
| 5 | 1750 | 2.0 | 2.0 | 1200 × 800 |
| 7 (default) | 2250 | 2.2 | 2.2 | 1320 × 880 |
| 10 | 3000 | 2.5 | 2.5 | 1500 × 1000 |

The **SVG viewBox is the working bitmap size**, not the source size. For `logo.png` (600 × 400)
the default export has `viewBox="0 0 1320 880"`.

### 2.2 Edge smoothing blur (applied before thresholding)

```
base  = scale > 1 ? 0.55 × scale : 0.5
blur  = base × (0.4 + 0.1 × smoothing)      // Smoothing 0 → ×0.4, 8 → ×1.2, 10 → ×1.4
blur  = blur × 0.75                          // only for lineart
sigma = round(blur, 2)                       // applied as -blur 0x<sigma> when sigma > 0.05
```

Default logo (scale 2.2, Smoothing 8): base 1.21 → sigma 1.45.

### 2.3 Speck threshold (Noise Removal)

Let `n` = Noise Removal, `s` = working scale.

```
fromSource  = n² / 8 × s²                    // n removes specks up to n²/8 source pixels²
fromWorking = s < 1 ? n² × 0.25 × (longWorking/1000)² : 0   // only for downscaled (large) images
factor      = outline → 2.0 ; lineart → 0.5 ; engrave → 1.0
turdsize    = round( max(fromSource, fromWorking) × factor )   // px² in working bitmap
```

If Advanced `speck` is set, `turdsize = round(speck × s²)` and the value is in source pixels².

The same number is passed to Potrace as `--turdsize` and to the optimiser as `min_area`.

Values for the default logo (s = 2.2, s² = 4.84, engrave):

| Noise Removal | turdsize (working px²) |
|---|---|
| 0 | 0 |
| 3 | 5 |
| 5 (default) | 15 |
| 7 | 30 |
| 9 | 49 |
| 10 | 61 |

The default keeps 2 × 2 source-pixel marks (about 19 working px²) and removes 1–2 px specks.
**Derived from these formulas (not measured):** a 3 × 3 source-pixel dot is about 43.6 working px²,
so it is removed from Noise Removal 9 upward. The fixture generator comment in
`scripts/make-fixtures.sh` says "needs Noise Removal 7+". That disagrees with the formula and is
listed in `docs/16-known-issues.md`.

### 2.4 Curve fitting (Potrace)

| Setting | Formula | Default (Smoothing 8) | Range |
|---|---|---|---|
| `--alphamax` (corner threshold) | `corners` set: `round(1.3334 × (1 − corners/10), 4)`; otherwise `round(0.55 + 0.06 × smoothing, 4)` | 1.03 | 0.55 … 1.15 (no corners: 1.3334) |
| `--opttolerance` (curve joining) | `tolerance` set, else `round(0.1 + 0.03 × smoothing, 3)` | 0.34 | 0.1 … 0.4 |
| Post-trace simplify tolerance (working px) | `base × max(1, longWorking / 2000)`, `base = tolerance` if set, else `0.2 + 0.04 × smoothing` | 0.52 | 0.2 … 0.6 for long sides under 2000 px |

`corners = 10` gives `--alphamax 0` (every corner stays sharp). `corners = 0` gives
`--alphamax 1.3334` (no corners preserved).

The raw trace uses `--longcurve` (`-n`), which keeps every fitted segment with no curve
optimisation. Its node count is `nodes_before` in the API.

Potrace is invoked as:

```
potrace --svg --turdsize <T> --alphamax <A> --unit 10 [--longcurve | --opttolerance <O>] --output <tmp.svg> <bitmap.pbm>
```

The SVG Potrace writes is read back, its `translate(…) scale(…)` group transforms are baked into
the coordinates, and only the geometry is kept. Any other transform form is rejected.

## 3. Image preparation (ImageMagick)

`prepareBitmap()` runs one `magick` invocation. The input is read as the format detected from
file content (`png:`, `jpeg:` or `webp:`) on frame `[0]`, so the `.bin` file name is irrelevant.

Standard (non-transparent or opaque images):

```
magick -limit memory 1GiB -limit map 1GiB -limit disk 2GiB -limit thread 2 -limit time <timeout−2>
  <fmt>:original.bin[0]
  -auto-orient
  -background white -alpha remove -alpha off -colorspace Gray
  -resize <W>x<H>!
  -auto-level
  [-brightness-contrast 0x<contrast>]      # only when Image contrast ≠ 0
  [-blur 0x<sigma>]                         # only when sigma > 0.05
  [-negate]                                 # only when Invert is on
  ( -auto-threshold OTSU | -threshold <t>% )  # OTSU when threshold is Auto
  -type bilevel
  pbm:bitmap-<key>.pbm
```

Transparent-background artwork (alpha shape) is used when `analyseTransparency()` decides the
picture is light artwork on transparency: after flattening onto white the mean grey is above
0.985 (measured on a copy resized to 400 × 400 or less). The alpha branch replaces the flatten and
grayscale steps with:

```
-alpha extract -negate                      # opaque pixels become black, transparent become white
```

It skips `-auto-level` and contrast. Resize, blur, invert and threshold still run.

Resource limits: `-limit time` is `max(5, timeout − 2)` seconds, where `timeout` is
`PICTOSVG_PROCESS_TIMEOUT` (default 40). Memory and map are 1 GiB, disk 2 GiB and threads 2
(`magick_memory_limit`, `magick_disk_limit`). ImageMagick is invoked through the Laravel Process
facade with an argument array and no shell.

Bitmap cache key: the bitmap file name is `bitmap-<sha256 prefix>.pbm`, where the hash covers
`lineart`, `detail`, `smoothing`, `threshold`, `contrast`, `invert` and the working size, blur,
threshold and alpha-branch flag. Changing Noise Removal, tolerance, corners, speck size or output
width does **not** re-run ImageMagick. Older bitmaps are deleted when a new one is written.

## 4. Preset differences

| Setting | Engrave (default) | Outline Cut | Line Art |
|---|---|---|---|
| Working target | `500 + 250·d` | same | `(500 + 250·d) × 1.25` |
| Blur | as §2.2 | as §2.2 | × 0.75 |
| Speck factor | 1.0 | 2.0 (stray islands cost when cutting) | 0.5 (keeps dots and hatching) |
| Output | filled paths, black fill, non-zero rule | closed contours stroked as hairlines, `fill="none"` | filled paths |
| Outline-only flag | off | on (`outline_only` defaults to true for this preset) | off |
| Warnings | none specific | open-contour count, check-before-cutting note | not a centerline note |

Outline Cut traces the edges of dark areas, so a drawn line becomes two cut lines. The pipeline
always says so in the warnings.

Line Art produces filled shapes that outline each line. It does **not** produce single-stroke
centerlines; the warning says so.

## 5. Transparency handling

| Case | Behaviour |
|---|---|
| Opaque image (`%[opaque]` not `False`) | flattened onto white, normal path |
| Partially transparent, mostly dark content | flattened onto white (transparent areas become white paper) |
| Partially transparent, light artwork (mean > 0.985 after flattening) | alpha shape: opaque pixels traced as artwork; a warning is added |
| Export "Transparent background" on (default) | no background rectangle |
| Export "Transparent background" off | `<rect fill="#ffffff">` is added, except in Outline mode |

Tested by `ConversionTest::test_transparent_png_is_flattened_onto_white_and_keeps_its_hole` and
`ConversionTest::test_light_artwork_on_transparent_background_traces_the_visible_shape`.

## 6. Contrast, invert and threshold

- **Image contrast** (−50 … 50, default 0): `-brightness-contrast 0x<n>` after auto-level.
- **Invert**: `-negate` after blur and before the threshold.
- **Threshold**: Auto (default) uses `-auto-threshold OTSU`. A manual value (1 … 99 %) uses
  `-threshold <t>%`. The UI shows 50 % when Auto is cleared.

## 7. Empty results

If Potrace finds nothing after speck removal, the conversion fails with `conversion_failed` (422):
"Nothing was found to trace. Try lowering Noise Removal, changing the threshold or using Invert
in Advanced Settings."

## 8. Limits and known gaps

- No colour tracing. Input is converted to grey and then to 1-bit.
- No raster despeckle filter. Specks are removed by Potrace's `--turdsize` and by the post-trace
  `min_area` check, not by a morphological filter.
- Photographs are approximate. The `vehicle-photo.jpg` fixture is synthetic, and no real
  photograph has been tested.
- EXIF orientation (5–8) is read only when PHP's `exif` extension is available. See
  `docs/16-known-issues.md`.
- Potrace has no memory limit. Only the process timeout applies to it.
