# ADR 0001: Potrace as the tracing engine

Status: Accepted (2026-11-21)

## Context

The master specification allows VTracer and/or Potrace. On the development machine VTracer is not
installed. There is no Homebrew formula, and cargo is not available. Potrace 1.16 is installed
through Homebrew.

Potrace is licensed under GPL-2.0-or-later. PicToSVG runs it as a separate command-line program.
It does not link to it or bundle it.

## Decision

Use Potrace 1.16 as the only tracing engine. Invoke it with `Process` and an argument array. Read
its SVG output and bake the transform into plain geometry (`VectorTracingService`).

VTracer is not integrated.

## Consequences

- Traces are one-bit outlines with Bézier curves. Colour tracing and centerline tracing are not
  possible with this engine. Line Art produces filled outlines, not centerlines. See
  [FUTURE-ENHANCEMENTS.md](../FUTURE-ENHANCEMENTS.md).
- Potrace's options (`--turdsize`, `--alphamax`, `--opttolerance`, `--longcurve`) are the only
  curve and speck controls. They are documented in [IMAGE-PROCESSING.md](../IMAGE-PROCESSING.md).
- Potrace has no memory limit. Only the process timeout bounds it.
- Distribution: PicToSVG does not bundle Potrace. Anyone who packages Potrace with the application
  must meet the GPL terms for that package. This has not been reviewed for any distribution.
- The Potrace version was checked only on macOS (1.16). Ubuntu packages must be checked with
  `potrace --version` before use.
