<?php

namespace App\Services\ImageProcessing;

use App\Exceptions\PicToSvgException;
use App\Support\ExternalTool;
use App\Support\ExternalToolFailed;

/**
 * Turns an uploaded raster image into a clean 1-bit bitmap (PBM) ready for tracing:
 * orientation → transparency → grayscale → resize to working size → contrast →
 * edge smoothing → threshold. All work is done by the ImageMagick CLI with explicit
 * resource limits; the original upload is never modified.
 */
class ImagePreprocessingService
{
    public function __construct(private readonly ExternalTool $tool) {}

    /**
     * Validates an uploaded file by CONTENT (never by extension) and returns its facts.
     *
     * @return array{mime: string, format: string, width: int, height: int}
     */
    public function inspect(string $file): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: '';
        $allowed = config('pictosvg.allowed_mimes');
        if (! isset($allowed[$mime])) {
            throw PicToSvgException::unsupportedImage();
        }
        $info = @getimagesize($file);
        $types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
        if ($info === false || ($types[$info[2]] ?? null) !== $mime || $info[0] < 1 || $info[1] < 1) {
            throw PicToSvgException::unsupportedImage();
        }
        [$width, $height] = [$info[0], $info[1]];
        if ($width < 8 || $height < 8) {
            throw PicToSvgException::unsupportedImage('This image is too small to convert. Please use a picture at least 8 pixels wide and tall.');
        }
        $maxPixels = (int) config('pictosvg.max_pixels');
        if ($width * $height > $maxPixels) {
            throw PicToSvgException::tooLarge(sprintf(
                'This image has too many pixels (%s megapixels). The limit is %s megapixels — please resize it and try again.',
                number_format($width * $height / 1e6, 1),
                number_format($maxPixels / 1e6, 0)
            ));
        }
        // Orientation metadata (EXIF in JPEG, PNG and WebP) can rotate the picture by 90°,
        // swapping the visible width and height. Ask the same tool that will apply
        // -auto-orient, so the reported size always matches what is traced.
        $orientation = trim($this->magick(['-ping', $allowed[$mime].':'.$file.'[0]', '-format', '%[orientation]', 'info:']));
        if (in_array($orientation, ['LeftTop', 'RightTop', 'RightBottom', 'LeftBottom'], true)) {
            [$width, $height] = [$height, $width];
        }

        return ['mime' => $mime, 'format' => $allowed[$mime], 'width' => $width, 'height' => $height];
    }

    /**
     * Decides how transparency is handled. Normally the image is flattened onto white.
     * If that leaves (almost) nothing dark — light artwork on a transparent background —
     * the visible (opaque) shape is used instead.
     *
     * @return array{opaque: bool, use_alpha_shape: bool}
     */
    public function analyseTransparency(string $file, string $format): array
    {
        $src = $format.':'.$file.'[0]';
        $opaque = trim($this->magick([$src, '-format', '%[opaque]', 'info:'])) !== 'False';
        if ($opaque) {
            return ['opaque' => true, 'use_alpha_shape' => false];
        }
        $mean = (float) trim($this->magick([
            $src, '-background', 'white', '-alpha', 'remove', '-alpha', 'off',
            '-colorspace', 'Gray', '-resize', '400x400>', '-format', '%[fx:mean]', 'info:',
        ]));

        return ['opaque' => false, 'use_alpha_shape' => $mean > 0.985];
    }

    /**
     * Writes the prepared bitmap.
     *
     * @param  array{width: int, height: int, contrast: int, blur: float, invert: bool, threshold: ?int, use_alpha_shape: bool}  $p
     */
    public function prepareBitmap(string $file, string $format, string $output, array $p): void
    {
        $args = [$format.':'.$file.'[0]', '-auto-orient'];
        if ($p['use_alpha_shape']) {
            // Opaque pixels become black artwork, transparent pixels white paper.
            array_push($args, '-alpha', 'extract', '-negate');
        } else {
            array_push($args, '-background', 'white', '-alpha', 'remove', '-alpha', 'off', '-colorspace', 'Gray');
        }
        array_push($args, '-resize', $p['width'].'x'.$p['height'].'!');
        if (! $p['use_alpha_shape']) {
            $args[] = '-auto-level';
            if ($p['contrast'] !== 0) {
                array_push($args, '-brightness-contrast', '0x'.$p['contrast']);
            }
        }
        if ($p['blur'] > 0.05) {
            array_push($args, '-blur', '0x'.round($p['blur'], 2));
        }
        if ($p['invert']) {
            $args[] = '-negate';
        }
        if ($p['threshold'] === null) {
            array_push($args, '-auto-threshold', 'OTSU');
        } else {
            array_push($args, '-threshold', $p['threshold'].'%');
        }
        array_push($args, '-type', 'bilevel', 'pbm:'.$output);

        $this->magick($args);
        if (! is_file($output) || filesize($output) < 8) {
            throw PicToSvgException::conversionFailed();
        }
    }

    /**
     * Returns a reduced PNG (longest side ≤ $maxSide) of either the uploaded original
     * ($format = jpeg|png|webp, flattened on white, upright) or a prepared bitmap ($format = pbm).
     */
    public function previewPng(string $file, string $format, int $maxSide = 1024): string
    {
        $args = [$format.':'.$file.'[0]'];
        if ($format !== 'pbm') {
            array_push($args, '-auto-orient', '-background', 'white', '-alpha', 'remove', '-alpha', 'off');
        }
        array_push($args, '-resize', $maxSide.'x'.$maxSide.'>', '-strip', 'png:-');
        $png = $this->magick($args);
        if (strncmp($png, "\x89PNG", 4) !== 0) {
            throw PicToSvgException::conversionFailed();
        }

        return $png;
    }

    /**
     * Counts the colours of an image. Returns [hex => pixelCount] with lowercase #rrggbb keys.
     *
     * @param  list<string>  $operations  ImageMagick operations applied before counting
     * @return array<string, int>
     */
    public function colourHistogram(string $file, string $format, array $operations = []): array
    {
        $out = $this->magick([$format.':'.$file.'[0]', ...$operations, '-depth', '8', '-format', '%c', 'histogram:info:']);
        $colours = [];
        if (preg_match_all('/^\s*(\d+):\s*\([^)]*\)\s*#([0-9A-Fa-f]{6})(?:[0-9A-Fa-f]{2})?\b/m', $out, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $hex = '#'.strtolower($row[2]);
                $colours[$hex] = ($colours[$hex] ?? 0) + (int) $row[1];
            }
        }

        return $colours;
    }

    /** Operations that give a small, point-sampled, flattened copy reduced to at most $max colours. */
    public function paletteProbeOperations(int $max, int $contrast): array
    {
        $ops = ['-auto-orient', '-background', 'white', '-alpha', 'remove', '-alpha', 'off', '-sample', '400x400>'];
        if ($contrast !== 0) {
            array_push($ops, '-brightness-contrast', '0x'.$contrast);
        }

        return [...$ops, '-dither', 'None', '-colors', (string) $max];
    }

    /**
     * Writes a copy of the picture in which every pixel is exactly one of $palette's colours
     * (no dithering).
     *
     * @param  list<string>  $palette  #rrggbb colours
     */
    public function quantise(string $file, string $format, string $output, array $palette, int $width, int $height, int $contrast): void
    {
        $paletteFile = $output.'.palette.png';
        try {
            $args = [];
            foreach ($palette as $hex) {
                $args[] = 'xc:'.$this->hex($hex);
            }
            $this->magick([...$args, '+append', 'png24:'.$paletteFile]);

            $args = [$format.':'.$file.'[0]', '-auto-orient', '-background', 'white', '-alpha', 'remove', '-alpha', 'off', '-resize', $width.'x'.$height.'!'];
            if ($contrast !== 0) {
                array_push($args, '-brightness-contrast', '0x'.$contrast);
            }
            array_push($args, '-dither', 'None', '-remap', 'png:'.$paletteFile);
            array_push($args, '-depth', '8', 'png24:'.$output);
            $this->magick($args);
        } finally {
            @unlink($paletteFile);
        }
        if (! is_file($output)) {
            throw PicToSvgException::conversionFailed();
        }
    }

    /** White-where-transparent mask of the upload at the given size (for pictures with alpha). */
    public function transparencyMask(string $file, string $format, string $output, int $width, int $height): void
    {
        $this->magick([$format.':'.$file.'[0]', '-auto-orient', '-alpha', 'extract', '-resize', $width.'x'.$height.'!', '-threshold', '50%', '-negate', '-depth', '8', 'png:'.$output]);
    }

    /**
     * Writes a greyscale mask (black = the given colours) of a quantised image, ready for
     * tracing. $clean removes one-pixel fringes (safe here because the mask has one channel).
     *
     * @param  list<string>  $colours  #rrggbb colours that belong to the layer
     */
    public function layerMask(string $quantised, array $colours, ?string $transparencyMask, bool $clean, string $output): void
    {
        $args = ['png:'.$quantised];
        foreach ($colours as $hex) {
            array_push($args, '-transparent', $this->hex($hex));
        }
        array_push($args, '-alpha', 'extract');
        if ($transparencyMask !== null) {
            array_push($args, 'png:'.$transparencyMask, '-compose', 'Lighten', '-composite');
        }
        if ($clean) {
            array_push($args, '-statistic', 'Mode', '3x3');
        }
        // 8-bit PGM: Potrace thresholds it at 50 %, and it is far quicker to write than 1-bit PBM.
        array_push($args, '-depth', '8', 'pgm:'.$output);
        $this->magick($args);
        if (! is_file($output) || filesize($output) < 8) {
            throw PicToSvgException::conversionFailed();
        }
    }

    /** Operations that reduce an image to its one-pixel border, for finding the background colour. */
    public function borderOperations(): array
    {
        return [
            '(', '-clone', '0', '-gravity', 'North', '-crop', 'x1+0+0', '+repage', ')',
            '(', '-clone', '0', '-gravity', 'South', '-crop', 'x1+0+0', '+repage', ')',
            '(', '-clone', '0', '-gravity', 'West', '-crop', '1x+0+0', '+repage', '-rotate', '90', ')',
            '(', '-clone', '0', '-gravity', 'East', '-crop', '1x+0+0', '+repage', '-rotate', '90', ')',
            '-delete', '0', '+append',
        ];
    }

    private function hex(string $hex): string
    {
        if (preg_match('/\A#[0-9a-f]{6}\z/', $hex) !== 1) {
            throw PicToSvgException::conversionFailed();
        }

        return $hex;
    }

    /**
     * The picture centred on a white canvas of exactly $width × $height (nothing is cropped).
     * Returns the PNG and the box the picture occupies, so the padding can be removed again.
     *
     * @return array{png: string, box: array{x: int, y: int, width: int, height: int}}
     */
    public function paddedPng(string $file, string $format, int $sourceWidth, int $sourceHeight, int $width, int $height): array
    {
        $scale = min($width / $sourceWidth, $height / $sourceHeight);
        $w = max(1, min($width, (int) round($sourceWidth * $scale)));
        $h = max(1, min($height, (int) round($sourceHeight * $scale)));
        $png = $this->magick([
            $format.':'.$file.'[0]', '-auto-orient', '-background', 'white', '-alpha', 'remove', '-alpha', 'off',
            '-resize', $w.'x'.$h.'!', '-gravity', 'center', '-extent', $width.'x'.$height, '-strip', 'png:-',
        ]);
        if (strncmp($png, "\x89PNG", 4) !== 0) {
            throw PicToSvgException::conversionFailed();
        }

        return ['png' => $png, 'box' => ['x' => intdiv($width - $w, 2), 'y' => intdiv($height - $h, 2), 'width' => $w, 'height' => $h]];
    }

    /**
     * Re-encodes an image received from outside as a plain, opaque, metadata-free PNG,
     * cropped to $crop (x, y, width, height). Nothing from the source file survives
     * except its pixels.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $crop
     */
    public function normaliseToPng(string $file, string $format, array $crop, string $output): void
    {
        $this->magick([
            $format.':'.$file.'[0]', '-background', 'white', '-alpha', 'remove', '-alpha', 'off',
            '-crop', $crop['width'].'x'.$crop['height'].'+'.$crop['x'].'+'.$crop['y'], '+repage',
            '-strip', 'png:'.$output,
        ]);
        if (! is_file($output) || filesize($output) < 32) {
            throw PicToSvgException::conversionFailed();
        }
    }

    /** @param list<string> $args */
    private function magick(array $args): string
    {
        $timeout = (int) config('pictosvg.process_timeout', 40);
        $limits = [
            '-limit', 'memory', (string) config('pictosvg.magick_memory_limit'),
            '-limit', 'map', (string) config('pictosvg.magick_memory_limit'),
            '-limit', 'disk', (string) config('pictosvg.magick_disk_limit'),
            '-limit', 'thread', '2',
            '-limit', 'time', (string) max(5, $timeout - 2),
        ];
        try {
            return $this->tool->run('ImageMagick', (string) config('pictosvg.magick_bin'), [...$limits, ...$args], $timeout);
        } catch (ExternalToolFailed) {
            throw PicToSvgException::unsupportedImage('This image could not be read. It may be damaged — please try another file.');
        }
    }
}
