# AI-002 — AI Suggest Settings (optional)

## Scope

A user-triggered button that asks Claude (Anthropic API) to choose values for the normal
conversion controls. It does not generate or alter geometry.

## Behaviour

- Enabled only when `ANTHROPIC_API_KEY` is set (`config/pictosvg.php` → `ai.anthropic_api_key`).
  Otherwise the button is disabled with an explanation and `/api/ai/suggest` returns `503`.
- On click the browser posts the current settings to `POST /api/ai/suggest`.
- `AiSuggestService` builds two PNGs with ImageMagick, longest side ≤ 1024 px: the upload
  (upright, flattened on white, metadata stripped) and the prepared 1-bit bitmap for the current
  settings — exactly what Potrace would trace.
- `ClaudeSettingsAdvisor` (official `anthropic-ai/sdk`) sends both images with a system prompt
  describing each control, requests JSON via `output_config.format` (JSON schema), model
  `PICTOSVG_AI_MODEL` (default `claude-opus-5-5`), effort `medium`, server-side refusal fallback
  (`fallbacks: "default"`, beta `server-side-fallback-2026-07-01`), timeout 45 s, no automatic retry. The API host is pinned by `PICTOSVG_AI_BASE_URL` (default `https://api.anthropic.com`), so an ambient `ANTHROPIC_BASE_URL` cannot redirect the key or the picture.
- The reply is type-checked and clamped (`AiSuggestService::sanitise`): unknown presets, out-of-range
  numbers, wrong types and extra fields cannot reach the pipeline. The explanation is stripped of
  markup and cut to 400 characters; the UI renders it with `textContent`.
- The UI applies the settings to the controls, shows the explanation and runs a normal conversion.

## Privacy and cost

- A reduced copy of the picture leaves the server only on an explicit click. Nothing is sent
  during normal conversion.
- Each click is one billed API request. Rate limit: 6 per minute per IP.
- The key is read server-side only and is never rendered into the page or returned by the API.

## Acceptance tests (`tests/Feature/AiSuggestTest.php`)

- `test_feature_is_off_without_a_key_and_conversion_is_unaffected`
- `test_key_presence_enables_the_button_and_never_reaches_the_page`
- `test_suggestion_is_built_from_the_picture_and_the_current_bitmap`
- `test_model_output_is_clamped_and_never_trusted`
- `test_auto_threshold_and_outline_preset_are_mapped`
- `test_requires_the_conversion_token_and_reports_ai_failures_cleanly`
- `test_real_adapter_refuses_to_call_out_without_a_key`

## Verification level

- **Automated Tested** with a fake at the `SettingsAdvisor` boundary (the Anthropic API is never
  called in tests).
- The real adapter was run once against a local stand-in HTTP server: request path `/v1/messages`,
  model, two base64 PNG image blocks, JSON-schema output config, fallback header and body were as
  intended, and the button flow worked in a browser.
- **Not Verified** against the live Anthropic API: no API key was available. Suggestion quality,
  latency and cost per click are unmeasured.

## Limitations

- One round only: the AI sees the current bitmap, not the result of its own suggestion.
- It cannot fix what thresholding cannot express (for example separating a subject from a busy
  background of similar brightness). That would need image redraw (AI-001, not implemented).
- No per-site spending cap beyond the per-IP rate limit.
