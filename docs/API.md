# PicToSVG HTTP API

All endpoints are stateless JSON endpoints under `/api`. There are no accounts and no database.
A conversion is identified by an opaque `id` (32 hex chars) plus a `token` (HMAC of the id,
64 hex chars) returned by the upload endpoint. Every later call must send both. A wrong token
yields `403`; a missing or malformed `id`/`token` in a JSON body yields `422`; an expired or
unknown id yields `404`. A result `version` that has been pruned (only the 5 newest are kept)
also yields `404` with code `expired`.

Errors always have the shape:

```json
{ "message": "Human readable, actionable text", "code": "machine_code" }
```

Validation errors use Laravel's standard `422` shape (`message` + `errors{field:[...]}`).

Error codes: `unsupported_image`, `image_too_large`, `invalid_token`, `expired`,
`trace_timeout`, `tool_missing`, `conversion_failed`, `invalid_svg`, `ai_unavailable`.

## POST /api/images/upload

`multipart/form-data`, field `image` (JPG/JPEG, PNG, WebP; max 20 MB; decoded pixel cap enforced).

`201` response:

```json
{
  "id": "9f2c…32hex",
  "token": "…64hex",
  "name": "logo.png",
  "width": 1200,
  "height": 800,
  "bytes": 48213,
  "mime": "image/png",
  "expires_in": 3600
}
```

`name` is a display label only (sanitised); it is never used as a filesystem path.
`width`/`height` are orientation-corrected pixel dimensions.

## POST /api/svg/convert

JSON body. Only `id` and `token` are required; everything else has a default.

| Field | Type | Default | Notes |
|---|---|---|---|
| `id`, `token` | string | — | from upload |
| `preset` | `engrave` \| `outline` \| `lineart` | `engrave` | |
| `detail` | int 1–10 | 7 | |
| `smoothing` | int 0–10 | 8 | |
| `noise` | int 0–10 | 5 | |
| `smooth_curves` | bool | true | "Optimize Smooth Curves" |
| `width` | number ≥ 0.01 | 150 | physical output width |
| `unit` | `mm` \| `in` | `mm` | |
| `threshold` | int 1–99 \| null | null = automatic | Advanced |
| `contrast` | int -50–50 | 0 | Advanced |
| `invert` | bool | false | Advanced |
| `tolerance` | number 0–5 \| null | null = from Smoothing | Advanced: curve simplification tolerance |
| `speck` | int 0–1000 \| null | null = from Noise Removal | Advanced: minimum speck size (px² of source image) |
| `corners` | int 0–10 \| null | null = from Smoothing | Advanced: corner preservation (10 = keep all corners sharp) |
| `outline_only` | bool | false (true for `outline` preset) | Advanced |
| `transparent_background` | bool | true | Advanced: false adds a white background rectangle |

`200` response:

```json
{
  "id": "…",
  "version": "a1b2c3d4e5f6",
  "svg": "<svg …>…</svg>",
  "stats": {
    "paths": 12,
    "nodes": 340,
    "nodes_before": 910,
    "width": 150,
    "height": 100,
    "unit": "mm",
    "bytes": 18234,
    "pixel_width": 1500,
    "pixel_height": 1000
  },
  "warnings": ["…plain-language warning…"],
  "engine": "potrace"
}
```

`svg` is the exact validated document that the download endpoint serves for this `version`.
`nodes_before` is the measured node count of the unoptimised trace of the same bitmap.

## POST /api/svg/optimize

Same body and response as `/api/svg/convert`, but it requires that the image was already
converted once and reuses the cached prepared bitmap when the image-preparation settings are
unchanged (it only re-fits and re-optimises the curves). Returns `409` with code
`conversion_failed` if no conversion exists yet.

## GET /api/svg/download/{temporaryId}?token=…&version=…&width=…&unit=…

Returns the SVG for `version` (default: latest) as `image/svg+xml` with
`Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and a restrictive
`Content-Security-Policy`. `width`/`unit` are optional; when given, only the root `width`/`height`
attributes are rewritten (the geometry and `viewBox` are never re-traced or deformed).

## GET /api/ai/status

```json
{ "configured": false, "message": "AI Redraw is not configured on this server. Standard conversion works without it." }
```

## POST /api/ai/redraw

Body `{id, token}`. Returns `503` with code `ai_unavailable` when no provider is configured.
No provider adapter ships in the MVP, so this endpoint currently always returns `503`.
