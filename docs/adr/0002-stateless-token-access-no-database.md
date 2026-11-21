# ADR 0002: Stateless token access and no database

Status: Accepted (2026-11-21)

## Context

The MVP needs upload, conversion, preview and download. It does not need accounts, history or
sharing. The product rules forbid a database unless a verified requirement justifies one.

## Decision

- Store each conversion in a private directory under `storage/app/private/pictosvg/<id>/`.
- Address it with a 128-bit random id (32 hex characters).
- Require a token that is `HMAC-SHA256("pictosvg:" + id, APP_KEY)` (64 hex characters) on every
  request after upload. Compare it with `hash_equals`.
- Expire directories after `ttl_minutes` (default 60), counted from upload. Clean them with
  `pictosvg:cleanup` (scheduled) and with a 1-in-20 sweep on upload.
- Use no database, no models and no queue. The queue connection is `sync`.

## Consequences

- Nothing persists beyond the TTL. There is no history and no recovery. The UI and the docs say so.
- Access depends on the id and token. Anyone holding both can download until expiry.
- Rotating `APP_KEY` invalidates all tokens. Conversions in progress then fail with `invalid_token`.
- The token is passed in the download URL's query string. It can appear in access logs and browser
  history. Moving it into a header would require a JavaScript download flow. That is not done yet.
- The expiry clock is not refreshed by activity. A user who keeps working after an hour must upload
  again.
- Concurrent work on the same id is not locked. Two conversions with different settings may race on
  the bitmap and `latest.json`.
- Horizontal scaling would need shared storage. That is not designed.
