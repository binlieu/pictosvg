<?php

namespace App\Http\Controllers;

use App\Exceptions\PicToSvgException;
use App\Services\ImageProcessing\ImagePreprocessingService;
use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Lottery;

class ImageUploadController extends Controller
{
    public function __invoke(Request $request, ConversionStore $store, ImagePreprocessingService $images): JsonResponse
    {
        $maxMb = (int) config('pictosvg.max_upload_mb');
        $file = $request->file('image');
        if (is_array($file) || $file === null) {
            throw PicToSvgException::unsupportedImage('Please choose one image file to upload.');
        }
        if (! $file->isValid() || $file->getSize() > $maxMb * 1024 * 1024) {
            throw PicToSvgException::tooLarge("This image is larger than {$maxMb} MB. Please choose a smaller file.");
        }

        $facts = $images->inspect($file->getRealPath());

        // Occasionally sweep expired conversions so storage stays bounded even without cron.
        Lottery::odds(1, 20)->winner(fn () => $store->cleanupExpired())->choose();

        ['id' => $id, 'token' => $token] = $store->create();
        $dir = $store->path($id);
        $file->move($dir, 'original.bin');
        @chmod($dir.'/original.bin', 0600);

        $name = $this->displayName((string) $file->getClientOriginalName());
        $store->writeJson($dir, 'meta.json', $facts + ['name' => $name, 'bytes' => filesize($dir.'/original.bin')]);

        return response()->json([
            'id' => $id,
            'token' => $token,
            'name' => $name,
            'width' => $facts['width'],
            'height' => $facts['height'],
            'bytes' => filesize($dir.'/original.bin'),
            'mime' => $facts['mime'],
            'expires_in' => $store->ttlSeconds(),
        ], 201);
    }

    /** A display label only — never used to build a filesystem path. */
    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN ._()\-]+/u', '_', $name) ?? '';
        $name = trim(mb_substr($name, 0, 120), ' .');

        return $name !== '' ? $name : 'image';
    }
}
