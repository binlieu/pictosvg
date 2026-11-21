# AI-001 — Optional AI Redraw

## Scope

`Original → optional AI Redraw → normal Trace → Optimise`. A user-triggered step that asks an
image model to redraw the upload as clean black-and-white line art, which the standard pipeline
then traces. The provider is OpenAI image editing (see [ADR 0005](../adr/0005-openai-image-editing-for-ai-redraw.md)).

## Behaviour

- Enabled only when `OPENAI_API_KEY` is set. Otherwise the **Redraw with AI** button is disabled
  with an explanation and `POST /api/ai/redraw` returns `503 ai_unavailable`.
- `OptionalAiRedrawService::redraw()`:
  1. Picks the closest canvas the model supports (`1024x1024`, `1536x1024`, `1024x1536`) and pads
     the upright, white-flattened, metadata-stripped upload onto it with ImageMagick. Nothing is cropped.
  2. `POST {base}/images/edits` (JSON) with `model` (`PICTOSVG_AI_IMAGE_MODEL`, default
     `gpt-image-2.6-sunburst`), the fixed prompt in `OptionalAiRedrawService::PROMPT`, the picture as a
     PNG data URL in `images[0].image_url`, `size`, `quality` (`PICTOSVG_AI_IMAGE_QUALITY`, default
     `medium`), `output_format: png`, `background: opaque`, `n: 1`. Timeout `PICTOSVG_AI_TIMEOUT`
     (150 s), no retry. The host is pinned by `PICTOSVG_AI_BASE_URL` (default `https://api.openai.com/v1`).
  3. Treats the answer (`data[0].b64_json`) as untrusted: strict base64, size cap, the same
     content-based validation as an upload, then re-encoding through ImageMagick to an opaque,
     metadata-free PNG, cropped back to the box the picture occupied so proportions match the original.
  4. Stores `redraw.png` + `redraw.json` in the conversion directory. `original.bin` is never touched.
     A failed redraw leaves any previous redraw in place.
- `GET /api/ai/redraw/{id}?token=…` serves the stored redraw for comparison.
- `/api/svg/convert` takes `source: original|redraw`. The redraw has its own bitmap-cache key
  (it includes the redraw version), so redrawing again is never traced from a stale cache. Results
  traced from a redraw carry a warning that AI may reinterpret, omit or alter details.
- UI: after a redraw, a **Picture to trace** switch (Original picture / AI redrawn picture) appears;
  the left preview panel shows the selected picture so the two can be compared, and the result is
  re-converted on each switch. Uploading or removing an image clears the redraw.

## Privacy and cost

- The picture leaves the server only on an explicit click. Normal conversion sends nothing anywhere.
- Each click is one billed request on the server's key. Rate limit: 4 per minute per IP. There is no
  other spending control.
- The key is read server-side only; it is never rendered into the page or returned by the API, and
  provider error bodies are not passed to the client.

## Acceptance tests (`tests/Feature/AiRedrawTest.php`)

- `test_feature_is_off_without_a_key_and_conversion_is_unaffected`
- `test_key_enables_the_button_and_never_reaches_the_browser`
- `test_redraw_sends_the_padded_picture_and_stores_the_answer_beside_the_original`
- `test_padding_is_removed_so_the_redraw_keeps_the_original_proportions`
- `test_user_can_trace_either_the_redraw_or_the_original`
- `test_redrawing_again_replaces_the_redraw_and_is_not_served_from_a_stale_cache`
- `test_requires_the_conversion_token_before_any_paid_call`
- `test_provider_failures_are_reported_cleanly_and_leave_everything_intact`
- `test_a_failed_redraw_keeps_the_previous_redraw`

## Verification level

- **Automated Tested**: OpenAI is replaced by `Http::fake`; stray HTTP requests are forbidden in tests.
- The button flow (redraw → compare → switch source → trace) was exercised in a browser against a
  local stand-in for the image endpoint; the request it received had the intended path, bearer
  header, model, size, quality and PNG data URL.
- **Not Verified against the live OpenAI API**: no key was available. The request format follows the
  OpenAI API reference as read on 2026-11-21 but has never been accepted by the real service, and
  redraw quality, latency and cost are unmeasured.

## Limitations

- The model may move, simplify or invent details; padding removal assumes it keeps the composition
  in place. Pixel-accurate alignment with the original is not guaranteed.
- Output resolution is at most 1536 px on the long side.
- Long requests need matching proxy timeouts; the PHP dev server blocks other requests meanwhile.
- If the default model id is not available to an account, set `PICTOSVG_AI_IMAGE_MODEL`.
