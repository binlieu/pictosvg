<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConvertRequest;
use App\Services\Ai\AiSuggestService;
use App\Services\Ai\OptionalAiRedrawService;
use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Optional AI features. Nothing here is on the path of a normal conversion. */
class AiRedrawController extends Controller
{
    public function status(OptionalAiRedrawService $redraw, AiSuggestService $suggest): JsonResponse
    {
        return response()->json([
            'configured' => $redraw->isConfigured(),
            'message' => $redraw->statusMessage(),
            'suggest_configured' => $suggest->isConfigured(),
        ]);
    }

    /** Claude looks at the picture and proposes values for the normal controls. */
    public function suggest(ConvertRequest $request, ConversionStore $store, AiSuggestService $suggest): JsonResponse
    {
        $data = $request->validated();
        $dir = $store->authorize($data['id'], $data['token']);

        return response()->json($suggest->suggest($dir, $data));
    }

    public function redraw(Request $request, ConversionStore $store, OptionalAiRedrawService $ai): JsonResponse
    {
        $dir = $store->authorize($request->input('id'), $request->input('token'));
        $ai->redraw($dir); // always throws ai_unavailable: image redraw is not implemented
    }
}
