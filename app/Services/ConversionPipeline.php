<?php

namespace App\Services;

use App\Exceptions\PicToSvgException;
use App\Services\ImageProcessing\ImagePreprocessingService;
use App\Services\Svg\PathGeometry;
use App\Services\Svg\SvgExportService;
use App\Services\Svg\SvgOptimizationService;
use App\Services\Svg\SvgValidationService;
use App\Services\Tracing\ColorPalette;
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
        $this->store->readJson($dir, 'meta.json') ?? throw PicToSvgException::expired();
        $source = $this->source($dir, $s);
        if ($requireExisting && $this->store->readJson($dir, 'latest.json') === null) {
            throw PicToSvgException::conversionFailed('Convert the image first.', 409);
        }
        $result = $s->preset === 'color'
            ? $this->traceColour($dir, $source, $s)
            : $this->traceMono($dir, $source, $s);
        $svg = $result['svg'];
        $rawNodes = $result['raw_nodes'];
        $warnings = $result['warnings'];
        if ($s->source === 'redraw') {
            array_unshift($warnings, 'This result was traced from the AI-redrawn picture. AI may reinterpret, omit or alter details — compare it with your original.');
        }
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

        $version = $s->versionKey($id.$source['tag']);
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
                'colors' => $result['colors'],
            ],
            'warnings' => $warnings,
            'engine' => VectorTracingService::ENGINE,
        ];
    }

    /**
     * Black-and-white presets: one prepared bitmap, traced raw and optimised.
     *
     * @return array{svg: string, raw_nodes: int, warnings: list<string>, colors: list<string>}
     */
    private function traceMono(string $dir, array $source, ConversionSettings $s): array
    {
        $warnings = [];
        // --- 1. Prepared bitmap (cached per image-preparation settings) -----------------
        ['bitmap' => $bitmap, 'size' => $size, 'long' => $long, 'analysis' => $analysis] = $this->prepareBitmap($dir, $source, $s);
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

        return ['svg' => $this->export->build($shapes, $exportOptions), 'raw_nodes' => $rawNodes, 'warnings' => $warnings, 'colors' => []];
    }

    /**
     * Colour preset: the picture is reduced to its solid colours and every colour becomes a
     * filled layer. Layers are stacked: layer k is traced from the area of colour k PLUS all
     * colours painted above it, so adjacent colours overlap underneath and never show a gap.
     *
     * @return array{svg: string, raw_nodes: int, warnings: list<string>, colors: list<string>}
     */
    private function traceColour(string $dir, array $source, ConversionSettings $s): array
    {
        $warnings = [];
        $size = $s->workingSize($source['width'], $source['height']);
        $long = max($size['width'], $size['height']);
        // Colours are separated and traced at (at most) the picture's own resolution, where the
        // tracer fits pixel edges best; the geometry is then scaled up to the output size.
        $qw = min($size['width'], $source['width']);
        $qh = min($size['height'], $source['height']);
        $up = $size['width'] / $qw;
        $tmp = fn (string $ext) => $dir.'/tmp-'.bin2hex(random_bytes(6)).'.'.$ext;
        $quant = $tmp('png');
        $alpha = null;
        $mask = $tmp('pgm');

        try {
            // 1. Choose the picture's solid colours.
            $probe = $this->preprocess->colourHistogram($source['file'], $source['format'], $this->preprocess->paletteProbeOperations(32, $s->contrast));
            $palette = ColorPalette::choose($probe, $s->colors ?? 12);
            if (count($palette) < 2) {
                throw PicToSvgException::conversionFailed('This picture has only one colour, so there is nothing to trace.');
            }

            // 2. Snap every pixel to one of those colours.
            $this->preprocess->quantise($source['file'], $source['format'], $quant, $palette, $qw, $qh, $s->contrast);
            $counts = $this->preprocess->colourHistogram($quant, 'png');
            arsort($counts);
            $border = $this->preprocess->colourHistogram($quant, 'png', $this->preprocess->borderOperations());
            arsort($border);

            $opaque = $source['tag'] !== 'original' || ($this->analysis($dir, $source)['opaque'] ?? true);
            if (! $opaque) {
                $alpha = $tmp('png');
                $this->preprocess->transparencyMask($source['file'], $source['format'], $alpha, $qw, $qh);
            }

            // 3. Paint order: background first, then the largest areas, small details on top.
            $order = array_keys($counts);
            $background = null;
            if ($opaque && $border !== [] && reset($border) / max(1, array_sum($border)) >= 0.5) {
                $background = (string) array_key_first($border);
                $order = array_values(array_unique([$background, ...$order]));
            }
            if ($background !== null && $s->transparentBackground) {
                array_shift($order); // leave the background out: nothing is drawn there
            }

            // 4. Trace each stacked layer.
            $speck = $s->speckArea($qw / $source['width'], max($qw, $qh));
            $base = ['turdsize' => $speck, 'alphamax' => $s->alphamax()];
            $scale = fn (array $shapes) => array_map(fn ($shape) => PathGeometry::transform($shape, $up, $size['height'] / $qh, 0.0, 0.0), $shapes);
            $layers = [];
            $fingerprints = [];
            $rawNodes = 0;
            foreach ($order as $index => $colour) {
                $this->preprocess->layerMask($quant, array_slice($order, $index), $alpha, $s->noise >= 3, $mask);
                if ($s->smoothCurves) {
                    foreach ($this->tracer->trace($mask, $tmp('svg'), $base + ['opttolerance' => null]) as $shape) {
                        foreach ($shape as $subpath) {
                            $rawNodes += PathGeometry::nodeCount($subpath);
                        }
                    }
                    $shapes = $this->optimizer->optimize(
                        $scale($this->tracer->trace($mask, $tmp('svg'), $base + ['opttolerance' => $s->optTolerance()])),
                        ['tolerance' => $s->simplifyTolerance($long), 'min_area' => $speck * $up * $up]
                    )['shapes'];
                } else {
                    $shapes = $scale($this->tracer->trace($mask, $tmp('svg'), $base + ['opttolerance' => null]));
                }
                if ($shapes === []) {
                    continue;
                }
                // A layer whose outline equals the one below it hides that one completely
                // (the lower colour has no visible pixels): replace instead of stacking.
                $fingerprint = md5(implode('|', array_map(fn ($shape) => PathGeometry::serialize($shape, 0), $shapes)));
                if ($layers !== [] && $fingerprints[count($layers) - 1] === $fingerprint) {
                    array_pop($layers);
                }
                $layers[] = ['color' => $colour, 'shapes' => $shapes];
                $fingerprints[count($layers) - 1] = $fingerprint;
            }
        } finally {
            @unlink($quant);
            @unlink($mask);
            if ($alpha !== null) {
                @unlink($alpha);
            }
        }
        if ($layers === []) {
            throw PicToSvgException::conversionFailed('Nothing was found to trace. Try lowering Noise Removal.');
        }
        if (count($palette) >= ($s->colors ?? 12)) {
            $warnings[] = 'This picture has more colours than the limit, so similar colours were merged. Photos and gradients convert only approximately.';
        }

        return [
            'svg' => $this->export->buildColor($layers, [
                'pixel_width' => $size['width'], 'pixel_height' => $size['height'], 'width' => $s->width, 'unit' => $s->unit,
            ]),
            'raw_nodes' => $rawNodes,
            'warnings' => $warnings,
            'colors' => array_column($layers, 'color'),
        ];
    }

    /** @return array{opaque: bool, use_alpha_shape: bool} */
    private function analysis(string $dir, array $source): array
    {
        $analysis = $this->store->readJson($dir, 'analysis.json');
        if ($analysis === null) {
            $analysis = $this->preprocess->analyseTransparency($source['file'], $source['format']);
            $this->store->writeJson($dir, 'analysis.json', $analysis);
        }

        return $analysis;
    }

    /**
     * The raster that will be traced: the untouched upload, or the AI-redrawn copy stored
     * beside it. The upload is never replaced.
     *
     * @return array{file: string, format: string, width: int, height: int, tag: string}
     */
    public function source(string $dir, ConversionSettings $s): array
    {
        if ($s->source === 'redraw') {
            $redraw = $this->store->readJson($dir, 'redraw.json');
            if ($redraw === null || ! is_file($dir.'/redraw.png')) {
                throw PicToSvgException::conversionFailed('Redraw the picture with AI first, or trace the original.', 409);
            }

            return ['file' => $dir.'/redraw.png', 'format' => 'png', 'width' => (int) $redraw['width'], 'height' => (int) $redraw['height'], 'tag' => 'redraw-'.$redraw['version']];
        }
        $meta = $this->store->readJson($dir, 'meta.json') ?? throw PicToSvgException::expired();

        return ['file' => $dir.'/original.bin', 'format' => $meta['format'], 'width' => (int) $meta['width'], 'height' => (int) $meta['height'], 'tag' => 'original'];
    }

    /**
     * Returns the prepared 1-bit bitmap for these settings, creating it if it is not cached.
     *
     * @param  array{file: string, format: string, width: int, height: int, tag: string}  $meta  from source()
     * @return array{bitmap: string, size: array{width: int, height: int, scale: float}, long: int, analysis: array{opaque: bool, use_alpha_shape: bool}}
     */
    public function prepareBitmap(string $dir, array $meta, ConversionSettings $s): array
    {
        $original = $meta['file'];
        if ($meta['tag'] !== 'original') {
            $analysis = ['opaque' => true, 'use_alpha_shape' => false]; // redraws are stored opaque
        } else {
            $analysis = $this->store->readJson($dir, 'analysis.json');
            if ($analysis === null) {
                $analysis = $this->preprocess->analyseTransparency($original, $meta['format']);
                $this->store->writeJson($dir, 'analysis.json', $analysis);
            }
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
        $bitmap = $dir.'/bitmap-'.$s->bitmapKey($params + ['source' => $meta['tag']]).'.pbm';
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
