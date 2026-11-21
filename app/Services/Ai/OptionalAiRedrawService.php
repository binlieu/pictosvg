<?php

namespace App\Services\Ai;

use App\Exceptions\PicToSvgException;
use App\Services\ImageProcessing\ImagePreprocessingService;
use App\Support\TemporaryFiles\ConversionStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional AI Redraw: Original → AI Redraw → normal Trace → Optimise.
 *
 * An image model (OpenAI image editing) redraws the upload as clean black-and-white
 * artwork. The redraw is stored BESIDE the original, never over it, and the user chooses
 * which one to trace. The standard conversion engine never calls this class.
 */
class OptionalAiRedrawService
{
    public const PROMPT = <<<'TXT'
Redraw this picture as clean black-and-white line art for laser engraving.
Keep the main subject exactly where it is, at the same size, with the same proportions and recognisable shapes.
Use only pure black lines and solid black shapes on a pure white background: no grey, no shading, no gradients, no texture, no colour.
Remove the background, shadows, reflections and small irrelevant details. Keep lines smooth, closed and reasonably thick.
Do not add text, borders, frames, signatures, watermarks or any new objects.
TXT;

    /** Output canvases every current GPT image model accepts. */
    private const CANVASES = [[1024, 1024], [1536, 1024], [1024, 1536]];

    public function __construct(
        private readonly ConversionStore $store,
        private readonly ImagePreprocessingService $images,
    ) {}

    public function isConfigured(): bool
    {
        return (string) config('pictosvg.ai.openai_api_key') !== '';
    }

    public function statusMessage(): string
    {
        return $this->isConfigured()
            ? 'AI Redraw is available.'
            : 'AI Redraw is not set up on this server. Standard conversion works without it.';
    }

    /**
     * @return array{width: int, height: int, version: string}
     */
    public function redraw(string $dir): array
    {
        if (! $this->isConfigured()) {
            throw PicToSvgException::aiUnavailable();
        }
        $meta = $this->store->readJson($dir, 'meta.json') ?? throw PicToSvgException::expired();

        // The picture is padded (never cropped) onto the closest canvas the model supports;
        // the same box is cut back out of the answer, restoring the original proportions.
        [$canvasW, $canvasH] = $this->canvasFor($meta['width'], $meta['height']);
        $input = $this->images->paddedPng($dir.'/original.bin', $meta['format'], $meta['width'], $meta['height'], $canvasW, $canvasH);

        $bytes = $this->requestRedraw($input['png'], $canvasW, $canvasH);

        $tmp = $dir.'/tmp-'.bin2hex(random_bytes(6)).'.ai';
        $out = $dir.'/tmp-'.bin2hex(random_bytes(6)).'.png';
        try {
            file_put_contents($tmp, $bytes);
            unset($bytes);
            // The answer is untrusted input: validate it like an upload, then re-encode it.
            $facts = $this->images->inspect($tmp);
            $kx = $facts['width'] / $canvasW;
            $ky = $facts['height'] / $canvasH;
            $crop = [
                'x' => (int) round($input['box']['x'] * $kx),
                'y' => (int) round($input['box']['y'] * $ky),
                'width' => max(8, (int) round($input['box']['width'] * $kx)),
                'height' => max(8, (int) round($input['box']['height'] * $ky)),
            ];
            $this->images->normaliseToPng($tmp, $facts['format'], $crop, $out);
            $size = getimagesize($out);
            if ($size === false || $size[2] !== IMAGETYPE_PNG) {
                throw PicToSvgException::conversionFailed('The AI returned a picture that could not be used. Please try again.', 502);
            }
            rename($out, $dir.'/redraw.png');
            @chmod($dir.'/redraw.png', 0600);
        } catch (PicToSvgException $e) {
            if ($e->errorCode === 'ai_unavailable') {
                throw $e;
            }
            throw new PicToSvgException('The AI returned a picture that could not be used. Please try again.', 'ai_unavailable', 502);
        } finally {
            @unlink($tmp);
            @unlink($out);
        }

        $result = ['width' => $size[0], 'height' => $size[1], 'version' => bin2hex(random_bytes(6))];
        $this->store->writeJson($dir, 'redraw.json', $result + ['model' => (string) config('pictosvg.ai.image_model')]);

        return $result;
    }

    /** Path of the stored redraw, or null when the picture has not been redrawn. */
    public function redrawFile(string $dir): ?string
    {
        return is_file($dir.'/redraw.png') && $this->store->readJson($dir, 'redraw.json') !== null ? $dir.'/redraw.png' : null;
    }

    /** @return array{0: int, 1: int} */
    private function canvasFor(int $width, int $height): array
    {
        $ratio = $width / $height;
        $best = self::CANVASES[0];
        foreach (self::CANVASES as $canvas) {
            if (abs(log($ratio / ($canvas[0] / $canvas[1]))) < abs(log($ratio / ($best[0] / $best[1])))) {
                $best = $canvas;
            }
        }

        return $best;
    }

    private function requestRedraw(string $png, int $width, int $height): string
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('pictosvg.ai.base_url'), '/'))
                ->withToken((string) config('pictosvg.ai.openai_api_key'))
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout((int) config('pictosvg.ai.timeout', 150))
                ->post('/images/edits', [
                    'model' => (string) config('pictosvg.ai.image_model'),
                    'prompt' => self::PROMPT,
                    'images' => [['image_url' => 'data:image/png;base64,'.base64_encode($png)]],
                    'size' => $width.'x'.$height,
                    'quality' => (string) config('pictosvg.ai.image_quality', 'medium'),
                    'output_format' => 'png',
                    'background' => 'opaque',
                    'n' => 1,
                ]);
        } catch (ConnectionException) {
            throw new PicToSvgException('The AI service could not be reached or took too long. Normal conversion still works.', 'ai_unavailable', 503);
        } catch (Throwable $e) {
            Log::error('PicToSVG AI: unexpected failure', ['error' => $e->getMessage()]);
            throw new PicToSvgException('AI Redraw failed unexpectedly. Normal conversion still works.', 'ai_unavailable', 503);
        }

        if (! $response->successful()) {
            $code = (string) $response->json('error.code', '');
            Log::warning('PicToSVG AI: API error', ['status' => $response->status(), 'code' => $code, 'type' => $response->json('error.type')]);
            throw match (true) {
                $code === 'moderation_blocked' => new PicToSvgException('The AI declined to redraw this picture. You can still convert the original.', 'ai_unavailable', 422),
                in_array($response->status(), [401, 403], true) => new PicToSvgException('AI Redraw is not available: the server\'s AI key was rejected or lacks access. Normal conversion still works.', 'ai_unavailable', 503),
                $response->status() === 429 => new PicToSvgException('The AI service is busy or over its limit right now. Please try again later.', 'ai_unavailable', 503),
                default => new PicToSvgException('The AI service returned an error. Normal conversion still works.', 'ai_unavailable', 503),
            };
        }

        $b64 = $response->json('data.0.b64_json');
        if (! is_string($b64) || $b64 === '' || strlen($b64) > (int) config('pictosvg.ai.max_response_bytes') * 4 / 3) {
            throw new PicToSvgException('The AI did not return a usable picture. Please try again.', 'ai_unavailable', 502);
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new PicToSvgException('The AI did not return a usable picture. Please try again.', 'ai_unavailable', 502);
        }

        return $bytes;
    }
}
