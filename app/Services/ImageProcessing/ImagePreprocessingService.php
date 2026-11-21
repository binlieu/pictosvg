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
