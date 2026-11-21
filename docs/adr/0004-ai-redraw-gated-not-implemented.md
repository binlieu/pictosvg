# ADR 0004: AI redraw is gated and not implemented

Status: Accepted (2026-11-21)

## Context

The master specification describes an optional AI redraw step before tracing. It must never block
standard conversion, and credentials must stay on the server. No provider has been chosen, and no
provider adapter exists.

## Decision

Ship only the gating:

- Configuration keys `PICTOSVG_AI_PROVIDER` and `PICTOSVG_AI_API_KEY`.
- `GET /api/ai/status` reports `configured: false` unless the provider name is in
  `OptionalAiRedrawService::IMPLEMENTED_PROVIDERS` (currently empty) **and** a key is set.
- `POST /api/ai/redraw` authorises the conversion, then throws `ai_unavailable` (503).
- The "AI Redraw" button is present but disabled, with the note "AI Redraw is not configured on this
  server. Standard conversion works without it."

No outbound request to any AI service is made.

## Consequences

- Credentials alone do not enable the feature. Adding a provider needs code: an adapter, an entry
  in `IMPLEMENTED_PROVIDERS` and tests.
- The API never returns the key. Tests check that `secret-key` does not appear in a response.
- Users cannot compare an original with an AI redraw. The "Original or AI redraw" choice is not
  implemented.
- Privacy, retention and cost documentation for a provider does not exist yet, because no provider
  exists.
- See [AI-001](../features/AI-001-optional-redraw.md) and [FUTURE-ENHANCEMENTS.md](../FUTURE-ENHANCEMENTS.md).
