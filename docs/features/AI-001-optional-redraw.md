# AI-001: Optional AI redraw

Status: Gated only. Redraw is not implemented. Verification: Automated Tested (gating and the 503
response).

## Scope

The gating for an optional AI redraw step before tracing. It covers configuration, status reporting,
the disabled button, and an endpoint that refuses cleanly. No provider is integrated. See
[ADR 0004](../adr/0004-ai-redraw-gated-not-implemented.md).

## Behaviour

- Configuration: `PICTOSVG_AI_PROVIDER` and `PICTOSVG_AI_API_KEY`. Both must be set **and** the provider must
  appear in `OptionalAiRedrawService::IMPLEMENTED_PROVIDERS`. That list is empty, so the feature is never
  configured.
- `GET /api/ai/status` returns `{configured, message}`. The message says that standard conversion works
  without AI.
- `POST /api/ai/redraw` with `{id, token}` checks the conversion authorisation first (403 on a bad
  token), then returns `503` with `ai_unavailable`.
- The web page shows the AI Redraw button disabled and the note "AI Redraw is not configured on this
  server. Standard conversion works without it."
- No outbound request is made.
- The AI routes are separate from the conversion path. Conversion never calls AI.

## Acceptance criteria

1. Status reports not configured by default.
2. Credentials alone do not enable the feature.
3. Redraw returns 503 `ai_unavailable` after authorisation.
4. The response never contains the key.
5. Standard conversion still works after a redraw attempt.
6. The home page shows the not-configured note.

## Tests

- `tests/Feature/DownloadAndLifecycleTest.php::test_ai_redraw_is_reported_unavailable_and_never_blocks_conversion`
- `tests/Feature/DownloadAndLifecycleTest.php::test_home_page_renders_the_three_steps_without_ai`

## Limitations

- No provider adapter, so no AI output, comparison or choice of source image.
- No privacy, retention, file size or cost documentation, because there is no provider.
- The button is always rendered (disabled). The master spec preferred showing it only when configured.
- The redraw endpoint is throttled as an upload route. Those 20 requests per minute are shared with uploads.
