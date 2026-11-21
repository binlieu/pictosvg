<?php

namespace App\Services\Ai;

/**
 * The external boundary of "AI Suggest Settings": something that can look at two PNGs
 * (the picture, and the black-and-white bitmap the current settings produce) and
 * answer with raw, unvalidated setting values.
 */
interface SettingsAdvisor
{
    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $current  the settings that produced $bitmapPng
     * @return array<string, mixed> raw suggestion (validated by AiSuggestService)
     */
    public function suggest(string $originalPng, string $bitmapPng, array $current): array;
}
