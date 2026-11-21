<?php

use App\Services\Ai\OptionalAiRedrawService;
use Illuminate\Support\Facades\Route;

Route::get('/', function (OptionalAiRedrawService $ai) {
    return view('home', ['config' => [
        'maxUploadMb' => (int) config('pictosvg.max_upload_mb'),
        'aiConfigured' => $ai->isConfigured(),
        'defaults' => config('pictosvg.defaults'),
    ]]);
});
