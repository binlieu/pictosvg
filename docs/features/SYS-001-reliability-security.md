# SYS-001: Reliability and security

Status: Implemented. Verification: Automated Tested. Ubuntu deployment: Not Verified.

## Scope

Temporary storage, opaque access, expiry and cleanup, process limits and timeouts, rate limits, error
hygiene and request validation. Applies across all endpoints. The architecture is in
[ARCHITECTURE.md](../ARCHITECTURE.md) §4–§8.

## Behaviour

- Storage: `storage/app/private/pictosvg/<id>/` with mode 0700 directories and 0600 original files.
  Not web-served.
- Ids: 128 random bits, 32 hex characters. Format is checked before any path is built.
- Tokens: `HMAC-SHA256("pictosvg:"+id, APP_KEY)`, 64 hex characters, compared with `hash_equals`.
- Expiry: 60 minutes from upload, by `meta.json` modification time. Expired conversations return `404
  expired` and their directory is deleted on access.
- Cleanup: `pictosvg:cleanup` every ten minutes (scheduler), plus a 1-in-20 sweep on upload. Only
  directories whose names are 32 hex characters are removed. Symlinks are skipped.
- Process limits: argument arrays, timeouts (`PICTOSVG_PROCESS_TIMEOUT`, default 40 s), ImageMagick
  `-limit` for memory, map, disk, threads and time. Potrace has the timeout only.
- Error hygiene: `PicToSvgException` gives short, actionable messages. Missing tools return `503
  tool_missing` with no path. Process errors are logged server-side.
- Rate limits: 20 uploads per minute and 180 conversion-type requests per minute, per IP.
- Validation: `ConvertRequest` rules for every setting. Invalid values return 422.
- Result retention: up to five result versions are kept per conversion. Temporary `tmp-*` files are
  removed after use, or after 300 seconds during a later conversion.

## Acceptance criteria

1. Each conversion is its own directory, and the upload's file name never becomes a path.
2. A wrong token, a token from another conversion, a malformed id and a traversal attempt are all refused.
3. Expired conversions are refused and removed, even before the scheduled cleanup runs.
4. Cleanup removes only expired id-named directories and leaves unrelated directories alone.
5. Tokens depend on the application key.
6. A missing tool returns `tool_missing` without leaking a path or the storage root.
7. Result versions are bounded.
8. Validation rejects out-of-range settings and unsafe unit values.

## Tests

- `tests/Feature/UploadTest.php::test_client_filename_is_never_used_as_a_path`
- `tests/Feature/UploadTest.php::test_rejects_non_images_regardless_of_extension`
- `tests/Feature/DownloadAndLifecycleTest.php`:
  - `test_download_requires_the_matching_token`
  - `test_expired_conversions_are_rejected_and_cleaned_up`
  - `test_expired_conversion_is_rejected_even_before_cleanup_runs`
  - `test_only_a_bounded_number_of_result_versions_is_kept`
  - `test_tokens_depend_on_the_application_key`
- `tests/Feature/ConversionTest.php`:
  - `test_validation_and_authorization_of_convert_requests`
  - `test_missing_tracer_binary_is_reported_without_leaking_paths`

## Limitations

- Rate limits are per IP. They are the only concurrency bound. Limits are not tested (the middleware
  is disabled in tests).
- The timeout path (`trace_timeout`) is not tested.
- No lock on a conversion id, so two conversions of the same id can race on the bitmap and `latest.json`.
- The download token is in the query string, which can reach access logs.
- Rotating `APP_KEY` invalidates all tokens.
- Expiry is not refreshed by activity.
- Ubuntu deployment, the scheduler and log handling are not verified.
- `APP_DEBUG=true` in `.env.example`. Production must set it to false.
