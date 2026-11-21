<?php

use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('pictosvg:cleanup', function (ConversionStore $store) {
    $this->info('Removed '.$store->cleanupExpired().' expired conversion(s).');
})->purpose('Delete expired temporary uploads and results');

Schedule::command('pictosvg:cleanup')->everyTenMinutes();
