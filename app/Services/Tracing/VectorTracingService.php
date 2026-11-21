<?php

namespace App\Services\Tracing;

use App\Exceptions\PicToSvgException;
use App\Services\Svg\PathGeometry;
use App\Support\ExternalTool;
use App\Support\ExternalToolFailed;
use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Adapter for Potrace 1.16 (bitmap → Bézier outlines).
 *
 * Potrace's own SVG is not used as the final file: it is in points and wraps everything
 * in a flipped, scaled group transform. This adapter reads it and returns plain geometry
 * in bitmap pixel coordinates (origin top-left, y down) with the transform baked in.
 */
class VectorTracingService
{
    public const ENGINE = 'potrace';

    public function __construct(private readonly ExternalTool $tool) {}

    /**
     * @param  array{turdsize: int, alphamax: float, opttolerance: ?float}  $options  opttolerance null = no curve optimisation
     * @return list<list<array>> shapes; each shape is a list of subpaths (outer contour first, then its holes)
     */
    public function trace(string $bitmap, string $workFile, array $options): array
    {
        $args = [
            '--svg',
            '--turdsize', (string) max(0, (int) $options['turdsize']),
            '--alphamax', number_format(max(0.0, min(1.3334, (float) $options['alphamax'])), 4, '.', ''),
            '--unit', '10',
        ];
        if ($options['opttolerance'] === null) {
            $args[] = '--longcurve'; // -n: keep every fitted segment (the "raw" trace)
        } else {
            array_push($args, '--opttolerance', number_format(max(0.0, (float) $options['opttolerance']), 3, '.', ''));
        }
        array_push($args, '--output', $workFile, $bitmap);

        try {
            $this->tool->run('Potrace', (string) config('pictosvg.potrace_bin'), $args);
        } catch (ExternalToolFailed) {
            throw PicToSvgException::conversionFailed();
        }

        try {
            // Parsing costs roughly 50× the file size in PHP memory; refuse early instead of
            // letting a very noisy image exhaust the worker.
            if ((int) @filesize($workFile) > (int) config('pictosvg.max_trace_bytes', 3 * 1024 * 1024)) {
                throw PicToSvgException::conversionFailed('This picture is too complex to convert at these settings. Try lowering Detail or raising Noise Removal.');
            }

            return $this->readPotraceSvg((string) @file_get_contents($workFile));
        } finally {
            @unlink($workFile);
        }
    }

    /** @return list<list<array>> */
    public function readPotraceSvg(string $svg): array
    {
        if ($svg === '') {
            throw PicToSvgException::conversionFailed('The result is too complex. Try lowering Detail or raising Noise Removal.');
        }
        // Potrace emits a DOCTYPE with an external DTD reference. It is our own tool's
        // output; the DTD is never fetched (LIBXML_NONET, no DTD loading, no entity substitution).
        $doc = new DOMDocument;
        if (! @$doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw PicToSvgException::conversionFailed();
        }

        $shapes = [];
        try {
            foreach ($doc->getElementsByTagName('path') as $path) {
                /** @var DOMElement $path */
                [$a, $d, $e, $f] = $this->groupTransform($path);
                $subpaths = PathGeometry::transform(PathGeometry::parse($path->getAttribute('d')), $a, $d, $e, $f);
                if ($subpaths !== []) {
                    $shapes[] = $subpaths;
                }
            }
        } catch (Throwable) {
            throw PicToSvgException::conversionFailed();
        }

        return $shapes;
    }

    /**
     * Potrace wraps paths in <g transform="translate(tx,ty) scale(sx,sy)">.
     * Returns [a, d, e, f] for x' = a·x + e, y' = d·y + f.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function groupTransform(DOMElement $path): array
    {
        $a = 1.0;
        $d = 1.0;
        $e = 0.0;
        $f = 0.0;
        $chain = [];
        for ($node = $path->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('transform')) {
                $chain[] = $node->getAttribute('transform');
            }
        }
        // innermost first: apply inner transform, then outer
        foreach ($chain as $transform) {
            if (preg_match('/\A\s*translate\(\s*(-?[\d.]+)[\s,]+(-?[\d.]+)\s*\)\s*scale\(\s*(-?[\d.]+)[\s,]+(-?[\d.]+)\s*\)\s*\z/', $transform, $m) !== 1) {
                throw new \RuntimeException('Unexpected transform from tracer.');
            }
            [$tx, $ty, $sx, $sy] = [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]];
            // new = T·S·(old)  →  x'' = sx·(a·x + e) + tx
            $a = $sx * $a;
            $e = $sx * $e + $tx;
            $d = $sy * $d;
            $f = $sy * $f + $ty;
        }

        return [$a, $d, $e, $f];
    }
}
