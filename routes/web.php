<?php

use App\Services\Ai\AiSuggestService;
use Illuminate\Support\Facades\Route;

Route::get('/', function (AiSuggestService $ai) {
    return view('home', ['config' => [
        'maxUploadMb' => (int) config('pictosvg.max_upload_mb'),
        'aiConfigured' => $ai->isConfigured(),
        'defaults' => config('pictosvg.defaults'),
    ]]);
});
