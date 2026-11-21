<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Exceptions\PicToSvgException;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Claude (Anthropic API, official PHP SDK) as the settings advisor. */
class ClaudeSettingsAdvisor implements SettingsAdvisor
{
    private const SYSTEM = <<<'TXT'
You tune an image-to-vector converter used for laser engraving and CNC cutting. The converter turns a picture into a pure black-and-white bitmap and traces the black areas into vector shapes. Black areas are what gets engraved or cut; white is left alone.

You are shown two images:
1. The picture the user uploaded.
2. The black-and-white bitmap that the CURRENT settings produce (this is exactly what would be traced).

Choose settings that make image 2 the best possible engraving/cutting artwork for the subject of image 1: the subject clearly recognisable, important details and lettering kept, backgrounds, shadows, paper texture, JPEG noise and stray specks removed. If the current bitmap is already good, keep the current values.

The controls:
- preset: "engrave" (filled black artwork; the normal choice for logos, text, silhouettes and photos), "outline" (only the contour lines of the shapes, for cutting a shape out), "lineart" (pictures that are thin drawn lines: sketches, technical drawings, handwriting; keeps finer strokes).
- detail (1-10): how many pixels are traced. Higher keeps small features; lower gives simpler shapes. 7 suits most artwork; 4-6 suits photos.
- smoothing (0-10): higher gives rounder, cleaner curves with fewer points; lower follows the pixels more literally. 8 suits most artwork; use 4-6 for tiny text or very fine detail.
- noise (0-10): removes isolated specks up to a size that grows with the value. 5 is the default; photos and scans usually need 7-10; clean artwork with small dots (i-dots, punctuation, stippling) needs 2-5.
- invert: true when the subject is LIGHT on a DARK background, so the subject should become the black (engraved) part.
- threshold_auto / threshold (1-99): the brightness cut between black and white. Auto works for clean two-tone artwork. Set threshold_auto=false with a value when the bitmap is too heavy (large areas wrongly black: lower the value) or too thin/broken (parts of the subject missing: raise the value). 50 is the middle.
- contrast (-50 to 50): raise (10-30) for faint, low-contrast or washed-out pictures; otherwise 0.

Give a short plain-language explanation (one or two sentences, no jargon) of what you changed and why, written for a hobbyist. If you kept everything, say the current settings already look good.
TXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'preset' => ['type' => 'string', 'enum' => ['engrave', 'outline', 'lineart']],
            'detail' => ['type' => 'integer'],
            'smoothing' => ['type' => 'integer'],
            'noise' => ['type' => 'integer'],
            'invert' => ['type' => 'boolean'],
            'threshold_auto' => ['type' => 'boolean'],
            'threshold' => ['type' => 'integer'],
            'contrast' => ['type' => 'integer'],
            'explanation' => ['type' => 'string'],
        ],
        'required' => ['preset', 'detail', 'smoothing', 'noise', 'invert', 'threshold_auto', 'threshold', 'contrast', 'explanation'],
        'additionalProperties' => false,
    ];

    public function isConfigured(): bool
    {
        return (string) config('pictosvg.ai.anthropic_api_key') !== '';
    }

    public function suggest(string $originalPng, string $bitmapPng, array $current): array
    {
        if (! $this->isConfigured()) {
            throw PicToSvgException::aiUnavailable();
        }
        $image = fn (string $png) => [
            'type' => 'image',
            'source' => ['type' => 'base64', 'mediaType' => 'image/png', 'data' => base64_encode($png)],
        ];

        try {
            $client = new Client(
                apiKey: (string) config('pictosvg.ai.anthropic_api_key'),
                baseUrl: (string) config('pictosvg.ai.base_url'),
            );
            // Thinking is adaptive by default on this model. Server-side fallback lets the API
            // retry on its default substitute model if the requested model declines.
            $message = $client->beta->messages->create(
                maxTokens: 16000,
                model: (string) config('pictosvg.ai.model'),
                system: self::SYSTEM,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Image 1 - the uploaded picture:'],
                        $image($originalPng),
                        ['type' => 'text', 'text' => 'Image 2 - the bitmap produced by the current settings:'],
                        $image($bitmapPng),
                        ['type' => 'text', 'text' => 'Current settings: '.json_encode($current).'. Reply with the settings to use.'],
                    ],
                ]],
                outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
                requestOptions: ['timeout' => (float) config('pictosvg.ai.timeout', 45), 'maxRetries' => 0],
            );
        } catch (AuthenticationException|PermissionDeniedException $e) {
            Log::error('PicToSVG AI: the Anthropic API key was rejected', ['status' => $e->status ?? null]);
            throw new PicToSvgException('AI Suggest is not available: the server\'s AI key was rejected. Normal conversion still works.', 'ai_unavailable', 503);
        } catch (RateLimitException) {
            throw new PicToSvgException('The AI service is busy right now. Please try again in a moment.', 'ai_unavailable', 503);
        } catch (APIConnectionException) {
            throw new PicToSvgException('The AI service could not be reached. Normal conversion still works.', 'ai_unavailable', 503);
        } catch (APIStatusException $e) {
            Log::warning('PicToSVG AI: API error', ['status' => $e->status ?? null, 'type' => $e->type?->value ?? null]);
            throw new PicToSvgException('The AI service returned an error. Normal conversion still works.', 'ai_unavailable', 503);
        } catch (PicToSvgException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('PicToSVG AI: unexpected failure', ['error' => $e->getMessage()]);
            throw new PicToSvgException('AI Suggest failed unexpectedly. Normal conversion still works.', 'ai_unavailable', 503);
        }

        if ($message->stopReason === 'refusal') {
            throw new PicToSvgException('The AI declined to analyse this picture. You can still adjust the settings yourself.', 'ai_unavailable', 422);
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        throw new PicToSvgException('The AI did not return usable settings. Please try again.', 'ai_unavailable', 502);
    }
}
