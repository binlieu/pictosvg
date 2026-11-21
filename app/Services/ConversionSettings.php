<?php

namespace App\Services;

/**
 * Translates the plain-language controls (preset + three sliders + optional advanced
 * overrides) into concrete, documented engine settings. See docs/IMAGE-PROCESSING.md.
 */
final class ConversionSettings
{
    public function __construct(
        public readonly string $preset,
        public readonly int $detail,
        public readonly int $smoothing,
        public readonly int $noise,
        public readonly bool $smoothCurves,
        public readonly float $width,
        public readonly string $unit,
        public readonly ?int $threshold,
        public readonly int $contrast,
        public readonly bool $invert,
        public readonly ?float $tolerance,
        public readonly ?int $speck,
        public readonly ?int $corners,
        public readonly bool $outline,
        public readonly bool $transparentBackground,
    ) {}

    public static function fromArray(array $in): self
    {
        $d = config('pictosvg.defaults');
        $preset = $in['preset'] ?? $d['preset'];

        return new self(
            preset: $preset,
            detail: (int) ($in['detail'] ?? $d['detail']),
            smoothing: (int) ($in['smoothing'] ?? $d['smoothing']),
            noise: (int) ($in['noise'] ?? $d['noise']),
            smoothCurves: (bool) ($in['smooth_curves'] ?? $d['smooth_curves']),
            width: (float) ($in['width'] ?? $d['width']),
            unit: $in['unit'] ?? $d['unit'],
            threshold: isset($in['threshold']) ? (int) $in['threshold'] : null,
            contrast: (int) ($in['contrast'] ?? 0),
            invert: (bool) ($in['invert'] ?? false),
            tolerance: isset($in['tolerance']) ? (float) $in['tolerance'] : null,
            speck: isset($in['speck']) ? (int) $in['speck'] : null,
            corners: isset($in['corners']) ? (int) $in['corners'] : null,
            outline: (bool) ($in['outline_only'] ?? ($preset === 'outline')),
            transparentBackground: (bool) ($in['transparent_background'] ?? true),
        );
    }

    /**
     * Working bitmap size. More Detail = more pixels to trace. Small images are enlarged
     * only modestly (about 2×): measured on the fixtures, larger enlargement makes edges
     * wobble and multiplies nodes instead of adding real detail.
     *
     * @return array{width: int, height: int, scale: float}
     */
    public function workingSize(int $sourceWidth, int $sourceHeight): array
    {
        $target = 500 + 250 * $this->detail;              // 750 … 3000 px on the long side
        if ($this->preset === 'lineart') {
            $target = (int) round($target * 1.25);       // thin strokes need more pixels
        }
        $target = min($target, (int) config('pictosvg.max_working_side'));
        $maxUpscale = 1.5 + 0.1 * $this->detail;          // 1.6× … 2.5×
        $long = max($sourceWidth, $sourceHeight);
        $scale = min($target / $long, $maxUpscale);
        $w = max(8, (int) round($sourceWidth * $scale));
        $h = max(8, (int) round($sourceHeight * $scale));

        return ['width' => $w, 'height' => $h, 'scale' => $w / $sourceWidth];
    }

    /**
     * Edge-smoothing blur (sigma, px at working size) applied before thresholding. It must
     * grow with the enlargement factor to hide interpolation stair-steps.
     */
    public function blur(float $scale): float
    {
        $base = $scale > 1 ? 0.55 * $scale : 0.5;
        $blur = $base * (0.4 + 0.1 * $this->smoothing);   // Smoothing 0 → 40 %, 8 → 120 %, 10 → 140 %

        return round($this->preset === 'lineart' ? $blur * 0.75 : $blur, 2);
    }

    /**
     * Speck area threshold in working pixels² (Potrace --turdsize and post-trace clean-up).
     * Noise Removal n removes specks up to n²/5 source pixels² (5 → 5 px², 10 → 20 px²),
     * with a floor relative to the working size so large photos are cleaned too.
     */
    public function speckArea(float $scale, int $workingLongSide): int
    {
        if ($this->speck !== null) {
            return (int) round($this->speck * $scale * $scale);
        }
        $fromSource = $this->noise ** 2 / 5 * $scale * $scale;
        $fromWorking = $this->noise ** 2 * 0.25 * ($workingLongSide / 1000) ** 2;
        $factor = match ($this->preset) {
            'outline' => 2.0,    // stray islands are costly when cutting
            'lineart' => 0.5,    // keep dots, hatching, fine marks
            default => 1.0,
        };

        return (int) round(max($fromSource, $fromWorking) * $factor);
    }

    /** Potrace --alphamax: 0 = every corner sharp, 1.3334 = no corners. */
    public function alphamax(): float
    {
        if ($this->corners !== null) {
            return round(1.3334 * (1 - $this->corners / 10), 4);
        }

        return round(0.55 + 0.06 * $this->smoothing, 4);   // 0.55 … 1.15 (Potrace default is 1.0)
    }

    /** Potrace --opttolerance (curve joining). */
    public function optTolerance(): float
    {
        return $this->tolerance ?? round(0.1 + 0.03 * $this->smoothing, 3);   // 0.1 … 0.4 (default 0.2)
    }

    /** Post-trace straight-run tolerance in working pixels. */
    public function simplifyTolerance(int $workingLongSide): float
    {
        $base = $this->tolerance ?? (0.2 + 0.04 * $this->smoothing);

        return round($base * max(1.0, $workingLongSide / 2000), 3);
    }

    /** Everything that changes the prepared bitmap (cache key input). */
    public function bitmapKey(array $extra): string
    {
        return substr(hash('sha256', json_encode([
            $this->preset === 'lineart', $this->detail, $this->smoothing, $this->threshold,
            $this->contrast, $this->invert, $extra,
        ])), 0, 16);
    }

    public function versionKey(string $id): string
    {
        return substr(hash('sha256', $id.json_encode(get_object_vars($this))), 0, 12);
    }
}
