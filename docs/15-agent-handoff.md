# Agent Handoff

Date: 2026-11-21. Read this with [14-current-status.md](14-current-status.md) and
[16-known-issues.md](16-known-issues.md).

## Read first

1. `CLAUDE.md` (project rules) and `docs/MASTER-SPEC.md` (product baseline).
2. `docs/ARCHITECTURE.md` and `docs/IMAGE-PROCESSING.md` before changing the pipeline.
3. `docs/adr/` before changing architecture. Do not reverse an ADR silently.
4. The feature spec for the area you change, in `docs/features/`.

## Rules that must not change without a decision

- No database, no accounts, no queue worker in the MVP.
- Potrace is the only tracing engine (ADR 0001).
- Argument arrays only for external programs. No shell.
- Every served SVG is validated. The download is byte-identical to the preview unless a width is given.
- Shapes are never merged. Holes and winding are preserved.
- Error messages never contain paths or command lines.
- The AI path stays gated and separate from conversion (ADR 0004).

## Current baseline

- 54 tests (`php artisan test`), 19 unit and 35 feature, reported passing for this milestone.
- Potrace 1.16 and ImageMagick 7 (`magick`) are installed on the development machine.
- The working tree was changed during this milestone (see KI-16). Re-read `SvgExportService` and
  `ConversionSettings` before relying on the numbers in the docs.

## Next safe tasks, in order

Each task should start with a short spec and a failing test where the task changes behaviour.

1. **KI-01 is resolved.** Orientation is read by ImageMagick for JPEG, PNG and WebP. Keep
   `UploadTest::test_png_orientation_metadata_is_honoured_like_jpeg` passing, and add a WebP orientation
   test, which KI-01 says is still missing.
2. **Correct the fixture comment (KI-03).** Measure the Noise Removal level at which the 3 × 3 dot disappears,
   then update `scripts/make-fixtures.sh` and the test comment.
3. **Importer verification.** Open a fixed set of exports (logo, transparent logo, text, line drawing,
   illustration, and an Outline Cut result) in LightBurn, Vectric Aspire or VCarve, and Inkscape. Record the
   application versions, imported size, closed-path status and stroke handling. Only then change any
   Importer Verified level.
4. **Real-photograph fixture.** Add a licensed or self-made photograph of a vehicle with clear licence
   terms. Record counts and a visual review. Do not replace the synthetic fixture without keeping it.
5. **Ubuntu deployment trial.** Follow `docs/INSTALLATION.md` §3 on a clean Ubuntu server. Check the ImageMagick
   version (`magick` or `convert`), `-auto-threshold`, the PHP extensions, upload limits, the Nginx limit and
   the scheduler. Update the instructions with what was actually run.
6. **Lock conversion ids (KI-08).** Serialise convert and optimise per id with a file lock in the storage
   directory. Add a test with two overlapping requests.
7. **Intersection detection for cut mode.** Write a spec first. Decide the algorithm and its tolerance. Add
   tests using a shape with a deliberate self-intersection. Warn only; do not modify geometry.
8. **Quality gate.** `scripts/check.sh` exists. Settle its documentation step (it expects `README.md`, which
   the task rules forbid unless asked for), then run the script and record the result, including Pint.
9. **AI provider adapter (only after a decision).** Choose one provider, write an ADR that supersedes
   ADR 0004, document privacy and retention, and add the adapter with tests.

## Things to avoid

- Do not describe any output as cut-safe or machine-verified.
- Do not claim importer compatibility without a run.
- Do not add a database or a queue for convenience.
- Do not change defaults (Detail 7, Smoothing 8, Noise Removal 5, width 150 mm) without a measured reason.
- Do not run `php artisan test` from a documentation-only task if it writes `.phpunit.result.cache` outside the
  allowed folders.

## Commands

```bash
php artisan test
php artisan test --testsuite=Unit
./scripts/make-fixtures.sh     # PICTOSVG_MAGICK_BIN=magick; regenerating may change counts
php artisan serve              # or the preview configuration in .claude/launch.json
```
