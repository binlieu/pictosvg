<?php

namespace App\Services;

use App\Exceptions\PicToSvgException;
use App\Services\ImageProcessing\ImagePreprocessingService;
use App\Services\Svg\PathGeometry;
use App\Services\Svg\SvgExportService;
use App\Services\Svg\SvgOptimizationService;
use App\Services\Svg\SvgValidationService;
use App\Services\Tracing\VectorTracingService;
use App\Support\TemporaryFiles\ConversionStore;

/**
 * Upload → prepared bitmap → raw trace → optimised trace → clean-up → export → validation.
 * Each stage is a separate service; this class only sequences them and caches the bitmap.
 */
class ConversionPipeline
{
    public function __construct(
        private readonly ConversionStore $store,
        private readonly ImagePreprocessingService $preprocess,
        private readonly VectorTracingService $tracer,
        private readonly SvgOptimizationService $optimizer,
        private readonly SvgExportService $export,
        private readonly SvgValidationService $validator,
    ) {}

    /** @return array<string, mixed> the /api/svg/convert response body */
    public function convert(string $id, string $dir, ConversionSettings $s, bool $requireExisting = false): array
    {
        $meta = $this->store->readJson($dir, 'meta.json') ?? throw PicToSvgException::expired();
        if ($requireExisting && $this->store->readJson($dir, 'latest.json') === null) {
            throw PicToSvgException::conversionFailed('Convert the image first.', 409);
        }
        $warnings = [];

        // --- 1. Prepared bitmap (cached per image-preparation settings) -----------------
        ['bitmap' => $bitmap, 'size' => $size, 'long' => $long, 'analysis' => $analysis] = $this->prepareBitmap($dir, $meta, $s);
        if ($analysis['use_alpha_shape']) {
            $warnings[] = 'This picture is light artwork on a transparent background, so its visible shape was traced.';
        }

        // --- 2. Trace: raw (no curve optimisation) and optimised ------------------------
        $speck = $s->speckArea($size['scale'], $long);
        $base = ['turdsize' => $speck, 'alphamax' => $s->alphamax()];
        $work = fn () => $dir.'/tmp-'.bin2hex(random_bytes(6)).'.svg';
        $raw = $this->tracer->trace($bitmap, $work(), $base + ['opttolerance' => null]);
        if ($raw === []) {
            throw PicToSvgException::conversionFailed(
                'Nothing was found to trace. Try lowering Noise Removal, changing the threshold or using Invert in Advanced Settings.'
            );
        }
        $exportOptions = [
            'pixel_width' => $size['width'],
            'pixel_height' => $size['height'],
            'width' => $s->width,
            'unit' => $s->unit,
            'outline' => $s->outline,
            'white_background' => ! $s->transparentBackground,
        ];
        // Measured node count of the unoptimised trace (same counting rule as the validator).
        $rawNodes = 0;
        foreach ($raw as $shape) {
            foreach ($shape as $subpath) {
                $rawNodes += PathGeometry::nodeCount($subpath);
            }
        }

        // --- 3. Optimise (separate from tracing; skipped when smooth curves is off) -----
        if ($s->smoothCurves) {
            unset($raw); // free the raw trace before the second one is loaded
            $shapes = $this->tracer->trace($bitmap, $work(), $base + ['opttolerance' => $s->optTolerance()]);
            $shapes = $this->optimizer->optimize($shapes, [
                'tolerance' => $s->simplifyTolerance($long),
                'min_area' => (float) $speck,
            ])['shapes'];
            if ($shapes === []) {
                throw PicToSvgException::conversionFailed(
                    'Nothing was left after clean-up. Try lowering Noise Removal in the settings.'
                );
            }
        } else {
            $shapes = $raw;
            unset($raw);
        }

        // --- 4. Export + validate the exact bytes that will be served -------------------
        $svg = $this->export->build($shapes, $exportOptions);
        if (strlen($svg) > (int) config('pictosvg.max_svg_bytes')) {
            throw PicToSvgException::conversionFailed('The result is too complex. Try lowering Detail or raising Noise Removal.');
        }
        $stats = $this->validator->validate($svg);

        if ($s->outline) {
            if ($stats['open_subpaths'] > 0) {
                $warnings[] = sprintf('%d outline(s) are not closed and may not cut cleanly.', $stats['open_subpaths']);
            }
            $warnings[] = 'Outlines follow the edges of dark areas, so a drawn line becomes two cut lines (one on each side). Always check the file in your laser/CNC software before cutting.';
        }
        if ($s->preset === 'lineart') {
            $warnings[] = 'Line Art traces the outline of each drawn line as a filled shape. It does not create single-stroke centerlines.';
        }
        if ($stats['paths'] > 1500) {
            $warnings[] = 'This result has a very large number of shapes. Photos convert only approximately — try raising Noise Removal or lowering Detail.';
        }

        $version = $s->versionKey($id);
        $this->store->writeAtomic($dir.'/result-'.$version.'.svg', $svg);
        $this->store->writeJson($dir, 'latest.json', ['version' => $version]);
        $this->pruneVersions($dir, $version);

        return [
            'id' => $id,
            'version' => $version,
            'svg' => $svg,
            'stats' => [
                'paths' => $stats['paths'],
                'nodes' => $stats['nodes'],
                'nodes_before' => $s->smoothCurves ? $rawNodes : $stats['nodes'],
                'width' => $stats['width'],
                'height' => $stats['height'],
                'unit' => $stats['unit'],
                'bytes' => strlen($svg),
                'pixel_width' => $stats['pixel_width'],
                'pixel_height' => $stats['pixel_height'],
            ],
            'warnings' => $warnings,
            'engine' => VectorTracingService::ENGINE,
        ];
    }

    /**
     * Returns the prepared 1-bit bitmap for these settings, creating it if it is not cached.
     *
     * @return array{bitmap: string, size: array{width: int, height: int, scale: float}, long: int, analysis: array{opaque: bool, use_alpha_shape: bool}}
     */
    public function prepareBitmap(string $dir, array $meta, ConversionSettings $s): array
    {
        $original = $dir.'/original.bin';
        $analysis = $this->store->readJson($dir, 'analysis.json');
        if ($analysis === null) {
            $analysis = $this->preprocess->analyseTransparency($original, $meta['format']);
            $this->store->writeJson($dir, 'analysis.json', $analysis);
        }
        $size = $s->workingSize($meta['width'], $meta['height']);
        $params = [
            'width' => $size['width'],
            'height' => $size['height'],
            'contrast' => $s->contrast,
            'blur' => $s->blur($size['scale']),
            'invert' => $s->invert,
            'threshold' => $s->threshold,
            'use_alpha_shape' => $analysis['use_alpha_shape'],
        ];
        $bitmap = $dir.'/bitmap-'.$s->bitmapKey($params).'.pbm';
        if (! is_file($bitmap)) {
            // Only remove bitmaps no concurrent request can still be tracing.
            foreach (glob($dir.'/bitmap-*.pbm') ?: [] as $old) {
                if (time() - (int) @filemtime($old) > 120) {
                    @unlink($old);
                }
            }
            $tmp = $dir.'/tmp-'.bin2hex(random_bytes(6)).'.pbm';
            try {
                $this->preprocess->prepareBitmap($original, $meta['format'], $tmp, $params);
                rename($tmp, $bitmap);
            } finally {
                @unlink($tmp);
            }
        }

        return ['bitmap' => $bitmap, 'size' => $size, 'long' => max($size['width'], $size['height']), 'analysis' => $analysis];
    }

    private function pruneVersions(string $dir, string $keep): void
    {
        $files = glob($dir.'/result-*.svg') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, (int) config('pictosvg.keep_versions', 5)) as $file) {
            if (! str_ends_with($file, 'result-'.$keep.'.svg')) {
                @unlink($file);
            }
        }
        foreach (glob($dir.'/tmp-*') ?: [] as $stale) {
            if (time() - (int) @filemtime($stale) > 300) {
                @unlink($stale);
            }
        }
    }
}
