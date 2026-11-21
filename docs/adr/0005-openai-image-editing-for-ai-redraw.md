# ADR 0005 — OpenAI image editing for AI Redraw

Date: 2026-11-21. Status: Accepted. Supersedes the "not implemented" part of ADR 0004.

## Context

Tracing separates dark from light, so photographs and busy pictures give poor vectors. The product
spec asks for an optional step that redraws such pictures as clean black-and-white artwork before
tracing. That needs a model that produces images.

A Claude-based "AI Suggest Settings" feature (Claude chose values for the existing controls) was
built on 2026-11-21 and removed the same day at the owner's request, together with the
`anthropic-ai/sdk` dependency. Claude reads images but does not generate them, so it could not
provide redraw.

## Decision

Implement AI Redraw with OpenAI's image-edit endpoint (`POST /v1/images/edits`), called with
Laravel's HTTP client. No SDK dependency is added. The feature is enabled only when
`OPENAI_API_KEY` is set and runs only on an explicit user click. The redraw is stored beside the
original and the user chooses which picture to trace.

## Consequences

- No new Composer dependency. The model id, quality, timeout and base URL are configuration.
- On a click, the user's picture is sent to a third party (OpenAI). Nothing is sent otherwise.
- Each click is billed to the server's key. The only spending control is a per-IP rate limit (4/min).
- Requests can take a minute or more: proxy timeouts must be raised, and the single-process dev
  server blocks other requests during a redraw. A queue would remove this (not implemented).
- The provider's answer is untrusted input and is validated and re-encoded before use.
- The integration has not been verified against the live API. Model ids change (for example
  `gpt-image-1` was shut down in October 2026), so the default may need updating.
- The standard conversion path has no dependency on any of this.
