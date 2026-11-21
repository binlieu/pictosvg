<?php

namespace App\Http\Controllers;

use App\Exceptions\PicToSvgException;
use App\Services\Ai\OptionalAiRedrawService;
use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Optional AI Redraw. Nothing here is on the path of a normal conversion. */
class AiRedrawController extends Controller
{
    public function status(OptionalAiRedrawService $ai): JsonResponse
    {
        return response()->json(['configured' => $ai->isConfigured(), 'message' => $ai->statusMessage()]);
    }

    /** User-triggered: sends the picture to the image model and stores the redraw beside the original. */
    public function redraw(Request $request, ConversionStore $store, OptionalAiRedrawService $ai): JsonResponse
    {
        $dir = $store->authorize($request->input('id'), $request->input('token'));

        return response()->json($ai->redraw($dir));
    }

    /** Serves the stored redraw so it can be compared with the original. */
    public function image(Request $request, string $temporaryId, ConversionStore $store, OptionalAiRedrawService $ai): Response
    {
        $dir = $store->authorize($temporaryId, $request->query('token'));
        $file = $ai->redrawFile($dir)
            ?? throw new PicToSvgException('This picture has not been redrawn yet.', 'expired', 404);

        return response((string) file_get_contents($file), 200, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
