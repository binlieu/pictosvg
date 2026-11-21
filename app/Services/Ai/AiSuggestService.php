<?php

namespace App\Services\Ai;

use App\Exceptions\PicToSvgException;
use App\Services\ConversionPipeline;
use App\Services\ConversionSettings;
use App\Services\ImageProcessing\ImagePreprocessingService;
use App\Support\TemporaryFiles\ConversionStore;

/**
 * Optional "AI Suggest Settings". The AI only CHOOSES values for the normal controls;
 * the vectors are still produced by the standard, local tracing pipeline. Nothing here
 * is called by a normal conversion.
 */
class AiSuggestService
{
    public function __construct(
        private readonly SettingsAdvisor $advisor,
        private readonly ConversionStore $store,
        private readonly ConversionPipeline $pipeline,
        private readonly ImagePreprocessingService $images,
    ) {}

    public function isConfigured(): bool
    {
        return $this->advisor->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $currentInput  validated convert-style settings currently shown in the UI
     * @return array{settings: array<string, mixed>, explanation: string}
     */
    public function suggest(string $dir, array $currentInput): array
    {
        if (! $this->isConfigured()) {
            throw PicToSvgException::aiUnavailable();
        }
        $meta = $this->store->readJson($dir, 'meta.json') ?? throw PicToSvgException::expired();
        $settings = ConversionSettings::fromArray($currentInput);

        // What the AI sees: a reduced copy of the upload and the exact bitmap that would be traced.
        $original = $this->images->previewPng($dir.'/original.bin', $meta['format']);
        $bitmap = $this->images->previewPng($this->pipeline->prepareBitmap($dir, $meta, $settings)['bitmap'], 'pbm');

        $current = [
            'preset' => $settings->preset,
            'detail' => $settings->detail,
            'smoothing' => $settings->smoothing,
            'noise' => $settings->noise,
            'invert' => $settings->invert,
            'threshold_auto' => $settings->threshold === null,
            'threshold' => $settings->threshold ?? 50,
            'contrast' => $settings->contrast,
        ];
        $raw = $this->advisor->suggest($original, $bitmap, $current);

        return [
            'settings' => $this->sanitise($raw, $current),
            'explanation' => $this->cleanText($raw['explanation'] ?? ''),
        ];
    }

    /** Never trust model output: every value is type-checked and clamped to the control's range. */
    private function sanitise(array $raw, array $current): array
    {
        $int = function (string $key, int $min, int $max) use ($raw, $current): int {
            $value = $raw[$key] ?? null;

            return is_int($value) || (is_float($value) && is_finite($value))
                ? max($min, min($max, (int) round($value)))
                : (int) $current[$key];
        };
        $bool = fn (string $key): bool => is_bool($raw[$key] ?? null) ? $raw[$key] : (bool) $current[$key];
        $preset = in_array($raw['preset'] ?? null, ['engrave', 'outline', 'lineart'], true) ? $raw['preset'] : $current['preset'];

        return [
            'preset' => $preset,
            'detail' => $int('detail', 1, 10),
            'smoothing' => $int('smoothing', 0, 10),
            'noise' => $int('noise', 0, 10),
            'invert' => $bool('invert'),
            'threshold' => $bool('threshold_auto') ? null : $int('threshold', 1, 99),
            'contrast' => $int('contrast', -50, 50),
            'outline_only' => $preset === 'outline',
        ];
    }

    private function cleanText(mixed $text): string
    {
        $text = is_string($text) ? $text : '';
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return mb_substr($text, 0, 400);
    }
}
