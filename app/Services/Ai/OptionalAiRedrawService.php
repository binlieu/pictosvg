<?php

namespace App\Services\Ai;

use App\Exceptions\PicToSvgException;

/**
 * Optional AI redraw (Original → AI Redraw → normal Trace). Fully isolated from the
 * standard conversion engine, which never calls this class.
 *
 * STATUS: no provider adapter is implemented in the MVP. The feature therefore always
 * reports as not configured, even when credentials are present, rather than pretending.
 */
class OptionalAiRedrawService
{
    /** Provider adapters that actually exist in this codebase. */
    private const IMPLEMENTED_PROVIDERS = [];

    public function isConfigured(): bool
    {
        $provider = (string) config('pictosvg.ai.provider');

        return $provider !== ''
            && (string) config('pictosvg.ai.api_key') !== ''
            && in_array($provider, self::IMPLEMENTED_PROVIDERS, true);
    }

    public function statusMessage(): string
    {
        return $this->isConfigured()
            ? 'AI Redraw is available.'
            : 'AI Redraw is not configured on this server. Standard conversion works without it.';
    }

    public function redraw(string $conversionDir): never
    {
        throw PicToSvgException::aiUnavailable();
    }
}
