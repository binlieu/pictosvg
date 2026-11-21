<?php

namespace App\Providers;

use App\Services\Ai\ClaudeSettingsAdvisor;
use App\Services\Ai\SettingsAdvisor;
use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SettingsAdvisor::class, ClaudeSettingsAdvisor::class);
        $this->app->singleton(ConversionStore::class, fn () => new ConversionStore(
            (string) config('pictosvg.storage_path'),
            (int) config('pictosvg.ttl_minutes'),
            (string) config('app.key'),
        ));
    }

    public function boot(): void
    {
        RateLimiter::for('pictosvg-upload', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        // Each AI suggestion is a paid API call: keep the per-IP budget small.
        RateLimiter::for('pictosvg-ai', fn (Request $r) => Limit::perMinute(6)->by($r->ip()));
        RateLimiter::for('pictosvg-convert', fn (Request $r) => Limit::perMinute(180)->by($r->ip()));
    }
}
