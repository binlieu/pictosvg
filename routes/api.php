<?php

use App\Http\Controllers\AiRedrawController;
use App\Http\Controllers\ImageUploadController;
use App\Http\Controllers\SvgConversionController;
use App\Http\Controllers\SvgDownloadController;
use Illuminate\Support\Facades\Route;

// Stateless JSON API. Access to a conversion requires its opaque id + HMAC token.
Route::post('/images/upload', ImageUploadController::class)->middleware('throttle:pictosvg-upload');
Route::post('/svg/convert', [SvgConversionController::class, 'convert'])->middleware('throttle:pictosvg-convert');
Route::post('/svg/optimize', [SvgConversionController::class, 'optimize'])->middleware('throttle:pictosvg-convert');
Route::get('/svg/download/{temporaryId}', SvgDownloadController::class)->middleware('throttle:pictosvg-convert');
Route::get('/ai/status', [AiRedrawController::class, 'status']);
Route::post('/ai/redraw', [AiRedrawController::class, 'redraw'])->middleware('throttle:pictosvg-ai');
Route::get('/ai/redraw/{temporaryId}', [AiRedrawController::class, 'image'])->middleware('throttle:pictosvg-convert');
