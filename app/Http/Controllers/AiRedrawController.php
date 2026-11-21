<?php

namespace App\Http\Controllers;

use App\Services\Ai\OptionalAiRedrawService;
use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiRedrawController extends Controller
{
    public function status(OptionalAiRedrawService $ai): JsonResponse
    {
        return response()->json(['configured' => $ai->isConfigured(), 'message' => $ai->statusMessage()]);
    }

    public function redraw(Request $request, ConversionStore $store, OptionalAiRedrawService $ai): JsonResponse
    {
        $dir = $store->authorize($request->input('id'), $request->input('token'));
        $ai->redraw($dir); // always throws ai_unavailable until a provider adapter exists
    }
}
