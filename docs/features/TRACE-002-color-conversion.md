# TRACE-002 — Color preset (flat-color pictures to colored vectors)

## Scope

Convert pictures made of solid colors into an SVG whose shapes have the same colors. Not a
photo or gradient vectorizer.

## Behaviour (`ConversionPipeline::traceColour`)

1. **Choose the colors.** A point-sampled copy (≤ 400 px, so no new blended colors are created) is
   reduced to 32 colors and counted. `ColorPalette::choose` walks them from most to least common and
   keeps a color unless it is a shade of a kept color (RGB distance < 40), tiny (< 0.05 % of the
   picture), an edge blend (< 2 % of the picture and within 30 of the line between two kept colors),
   or over the limit (`colors`, default 12). Kept colors are exact colors from the picture.
2. **Quantise.** Every pixel is snapped to the nearest chosen color (`-remap`, no dithering) at the
   picture's own resolution (or the working size, if that is smaller).
3. **Order the layers.** The color covering at least half of the picture's one-pixel border is the
   background and goes first; the rest follow from largest to smallest area. With
   `transparent_background: true` the background layer is left out. Pictures with transparency have
   no background layer; transparent pixels are excluded from every layer.
4. **Trace stacked layers.** Layer *k* is traced from the area of color *k* plus every color painted
   above it, so neighbouring colors overlap underneath and no gap can show between them. Each layer
   mask is a one-channel image; with Noise Removal ≥ 3 one-pixel fringes are removed (3×3 mode
   filter). Potrace traces the mask at that resolution and the geometry is scaled to the output
   size. A layer whose outline equals the layer above it (nothing of it would be visible) is dropped.
5. **Export.** One `<g fill="#rrggbb">` per color, bottom layer first. Same validation, sizing,
   download and optimisation as the other presets. Detail, Smoothing and Noise Removal apply;
   threshold, invert and outline-only do not.

## Measured on the fixtures (dev machine, default settings)

| Picture | Colors | Paths | Nodes (raw) | Time |
|---|---|---|---|---|
| `illustration.png` (5 flat colors) | 4 + background | 18 | 166 (481) | ~1.8 s |
| `transparent-logo.png` | 1 | 2 | — | ~0.7 s |
| `vehicle-photo.jpg` (generated photo-like scene) | 6 | 910 | 12 048 (15 488) | ~3.0 s |

Tracing each layer at the picture's own resolution instead of an enlarged, blurred mask cut the
illustration from 1 148 nodes and 4.9 s to 166 nodes and 1.8 s.

## Acceptance tests (`tests/Feature/ColorConversionTest.php`)

- `test_solid_colours_are_kept_exactly_and_in_the_right_places`
- `test_layers_overlap_so_neighbouring_colours_leave_no_gaps`
- `test_background_colour_can_be_left_out`
- `test_number_of_colours_can_be_limited`
- `test_transparent_pictures_keep_transparency_and_have_no_hidden_layers`
- `test_photo_like_picture_converts_approximately_within_the_colour_limit`
- `test_single_colour_picture_and_bad_parameters_are_reported`
- `test_colour_result_downloads_and_resizes_like_any_other`
- `test_palette_merges_edge_blends_but_keeps_small_distinct_colours`

## Verification level

Automated Tested; fixture outputs rendered and reviewed by the developer agent; the preset was
exercised in a browser. **Not Verified** in LightBurn, Inkscape or VCarve, and not on real
photographs or scanned artwork.

## Limitations

- Photos and gradients become bands of flat color with ragged edges and many shapes.
- With "leave out the background color", every area of that color becomes empty, including
  enclosed ones (for example the white of an eye on a white background).
- One-pixel-wide lines can be removed by the fringe clean-up; set Noise Removal below 3 to keep them.
- Because layers overlap, cutting the shapes apart physically would give overlapping pieces; the
  result is meant for colored display, print, or per-color engraving layers.
- Colors are sRGB hex values; no color-profile conversion is done.
