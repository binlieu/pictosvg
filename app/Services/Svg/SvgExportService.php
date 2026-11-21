<?php

namespace App\Services\Svg;

use InvalidArgumentException;

/**
 * Builds the final standalone SVG from numeric geometry only (no text from the upload or
 * from the tracer is ever copied into the document), and re-dimensions an existing
 * export without touching its geometry.
 */
class SvgExportService
{
    public const MM_PER_INCH = 25.4;

    /**
     * @param  list<list<array>>  $shapes
     * @param  array{pixel_width: int, pixel_height: int, width: float, unit: string, outline: bool, white_background: bool, precision?: int}  $o
     */
    public function build(array $shapes, array $o): string
    {
        $w = (int) $o['pixel_width'];
        $h = (int) $o['pixel_height'];
        $precision = $o['precision'] ?? 1;
        [$width, $height] = $this->physicalSize((float) $o['width'], $w, $h);
        $unit = $this->unit($o['unit']);

        $svg = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .sprintf('<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="%s%s" height="%s%s" viewBox="0 0 %d %d">', $width, $unit, $height, $unit, $w, $h)."\n";
        if ($o['white_background'] && ! $o['outline']) {
            $svg .= sprintf('<rect width="%d" height="%d" fill="#ffffff"/>', $w, $h)."\n";
        }
        if ($o['outline']) {
            // Hairline that stays ~0.1% of the drawing width; laser/CNC software ignores stroke width.
            $stroke = rtrim(rtrim(number_format(max($w, $h) / 1000, 2, '.', ''), '0'), '.') ?: '1';
            $svg .= sprintf('<g fill="none" stroke="#000000" stroke-width="%s" stroke-linejoin="round">', $stroke)."\n";
        } else {
            $svg .= '<g fill="#000000" fill-rule="nonzero" stroke="none">'."\n";
        }
        foreach ($shapes as $shape) {
            $svg .= '<path d="'.PathGeometry::serialize($shape, $precision).'"/>'."\n";
        }

        return $svg."</g>\n</svg>\n";
    }

    /**
     * Rewrites ONLY the root width/height. The viewBox and every path stay byte-identical,
     * so re-dimensioning can never deform or re-trace the artwork.
     */
    public function withDimensions(string $svg, float $width, string $unit): string
    {
        if (preg_match('/<svg\b[^>]*\bviewBox="0 0 (\d+) (\d+)"[^>]*>/', $svg, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new InvalidArgumentException('Not a PicToSVG export.');
        }
        [$tag, $offset] = $m[0];
        [$w, $h] = $this->physicalSize($width, (int) $m[1][0], (int) $m[2][0]);
        $unit = $this->unit($unit);
        $count = 0;
        $newTag = preg_replace(
            ['/\swidth="[^"]*"/', '/\sheight="[^"]*"/'],
            [' width="'.$w.$unit.'"', ' height="'.$h.$unit.'"'],
            $tag, 1, $count
        );
        if ($count !== 2) {
            throw new InvalidArgumentException('Not a PicToSVG export.');
        }

        return substr($svg, 0, $offset).$newTag.substr($svg, $offset + strlen($tag));
    }

    /** @return array{0: string, 1: string} width and height as trimmed decimal strings */
    public function physicalSize(float $width, int $pixelWidth, int $pixelHeight): array
    {
        if ($width <= 0 || $pixelWidth < 1 || $pixelHeight < 1 || ! is_finite($width)) {
            throw new InvalidArgumentException('Invalid dimensions.');
        }
        $height = $width * $pixelHeight / $pixelWidth;

        return [$this->number($width), $this->number($height)];
    }

    private function number(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    private function unit(string $unit): string
    {
        return match ($unit) {
            'mm', 'in' => $unit,
            default => throw new InvalidArgumentException('Unsupported unit.'),
        };
    }
}
