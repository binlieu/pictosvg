# VIEW-001: Preview and controls

Status: Implemented. Verification: Automated Tested only for the rendered page's markup. Interactive
behaviour: Not Verified (no browser tests).

## Scope

The single Blade page with three numbered steps: upload, convert and preview, download. The
settings sliders, Advanced Settings, the preview viewports, the statistics and the output-size
controls, all driven by `public/js/pictosvg.js`.

## Behaviour

- Step 2: three preset cards (Engrave, Outline Cut, Line Art), the Optimize Smooth Curves checkbox,
  three sliders (Detail 1–10, Smoothing 0–10, Noise Removal 0–10), Convert to SVG, Reset Settings,
  and a collapsible Advanced Settings section.
- Advanced Settings: threshold, curve tolerance, speck size and corner preservation (each with an
  Auto checkbox that is on by default); image contrast; invert; outline-only; transparent background.
- Choosing Outline Cut turns on Outline-only output. Other presets turn it off.
- Changing a setting after the first successful conversion re-converts after 450 ms. A new request
  aborts the previous one, and stale responses are ignored.
- The Convert button is disabled while uploading or converting. A spinner and "Converting…" show during
  a conversion.
- Step 3: Original Image and SVG Result panels, side by side above 992 px and stacked below. The SVG
  view hides the original. Zoom buttons, wheel zoom (0.5×–32×), drag pan, double-click or `0` to fit,
  arrow keys to pan. Background: transparent checker or white.
- Stats: paths, nodes (with "was N before optimization" when the raw count is higher), output size
  and file size in bytes.
- Warnings appear as dismissible alerts.
- Output width (default 150) and unit (mm or in). Changing the unit converts the width value.
  Height is calculated from the aspect ratio. Changing the size only rescales the download.
- Download link: enabled once a result and a valid width exist.
- AI Redraw button: disabled, with the note that AI is not configured.

## Acceptance criteria

1. The page renders Upload Image, Convert to SVG, Download SVG, the three presets, Optimize Smooth Curves,
   Advanced Settings and the AI note, with same-origin asset URLs.
2. The Convert button is the only primary action in step 2.
3. Sliders and Advanced controls are sent with each conversion.
4. Stale responses cannot overwrite a newer result.
5. Download is disabled until a result exists.

## Tests

- `tests/Feature/DownloadAndLifecycleTest.php::test_home_page_renders_the_three_steps_without_ai`
  asserts the labels above and that assets are same-origin relative URLs.
- Interactive behaviour (debounce, zoom, pan, view switching, backgrounds, paste) has no tests.

## Limitations

- No browser tests. No device testing. No accessibility audit.
- The UI is dark only. The CSS defines no light theme.
- Advanced labels use technical words such as "curve simplification tolerance".
- `POST /api/svg/optimize` exists in the API but the UI does not call it.
- The AI button always appears. The master spec allowed it to appear only when configured.
