<?php

namespace App\Providers;

use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConversionStore::class, fn () => new ConversionStore(
            (string) config('pictosvg.storage_path'),
            (int) config('pictosvg.ttl_minutes'),
            (string) config('app.key'),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('pictosvg-upload', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('pictosvg-convert', fn (Request $r) => Limit::perMinute(90)->by($r->ip()));
    }
}
