<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConvertRequest;
use App\Services\ConversionPipeline;
use App\Services\ConversionSettings;
use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Http\JsonResponse;

class SvgConversionController extends Controller
{
    public function convert(ConvertRequest $request, ConversionStore $store, ConversionPipeline $pipeline): JsonResponse
    {
        return $this->run($request, $store, $pipeline, false);
    }

    /** Re-fit / re-optimise an image that has already been converted once. */
    public function optimize(ConvertRequest $request, ConversionStore $store, ConversionPipeline $pipeline): JsonResponse
    {
        return $this->run($request, $store, $pipeline, true);
    }

    private function run(ConvertRequest $request, ConversionStore $store, ConversionPipeline $pipeline, bool $requireExisting): JsonResponse
    {
        $data = $request->validated();
        $dir = $store->authorize($data['id'], $data['token']);

        return response()->json(
            $pipeline->convert($data['id'], $dir, ConversionSettings::fromArray($data), $requireExisting),
            200, [], JSON_UNESCAPED_SLASHES
        );
    }
}
