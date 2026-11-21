<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI-001: optional AI Redraw (Original → AI Redraw → Trace). OpenAI is never called in tests:
 * Http::fake stands in and stray requests are forbidden (see Tests\TestCase).
 */
class AiRedrawTest extends TestCase
{
    private const KEY = 'sk-test-not-a-real-key';

    private function enable(): void
    {
        config(['pictosvg.ai.openai_api_key' => self::KEY]);
    }

    /** A "redrawn" picture: white canvas with one black rectangle given in canvas fractions. */
    private function fakePng(int $width, int $height, array $rect = [0.25, 0.25, 0.75, 0.75]): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, (int) ($rect[0] * $width), (int) ($rect[1] * $height), (int) ($rect[2] * $width), (int) ($rect[3] * $height), imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function fakeOpenAi(?string $png = null, int $width = 1536, int $height = 1024): void
    {
        Http::fake(['https://openai.invalid/v1/images/edits' => Http::response([
            'created' => 1, 'data' => [['b64_json' => base64_encode($png ?? $this->fakePng($width, $height))]],
            'usage' => ['total_tokens' => 1],
        ])]);
    }

    private function redraw(array $upload)
    {
        return $this->postJson('/api/ai/redraw', ['id' => $upload['id'], 'token' => $upload['token']]);
    }

    public function test_feature_is_off_without_a_key_and_conversion_is_unaffected(): void
    {
        Http::fake();
        $this->getJson('/api/ai/status')->assertOk()->assertJsonPath('configured', false);
        $upload = $this->upload('logo.png');

        $this->redraw($upload)->assertStatus(503)->assertJsonPath('code', 'ai_unavailable');
        $this->getJson('/api/ai/redraw/'.$upload['id'].'?token='.$upload['token'])->assertStatus(404);
        $this->postJson('/api/svg/convert', ['id' => $upload['id'], 'token' => $upload['token'], 'source' => 'redraw'])->assertStatus(409);
        $this->convert($upload);
        Http::assertNothingSent();
    }

    public function test_key_enables_the_button_and_never_reaches_the_browser(): void
    {
        $this->enable();
        $this->getJson('/api/ai/status')->assertOk()->assertJsonPath('configured', true);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('"aiConfigured":true', $html);
        $this->assertStringContainsString('Sends your picture to OpenAI', $html);
        $this->assertStringNotContainsString(self::KEY, $html);
    }

    public function test_redraw_sends_the_padded_picture_and_stores_the_answer_beside_the_original(): void
    {
        $this->enable();
        $this->fakeOpenAi();
        $upload = $this->upload('logo.png'); // 600x400 → 1536x1024 canvas, no padding

        $response = $this->redraw($upload)->assertOk();
        $this->assertSame([1536, 1024], [$response->json('width'), $response->json('height')]);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{12}\z/', $response->json('version'));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $image = $request['images'][0]['image_url'] ?? '';
            $png = base64_decode(substr($image, strlen('data:image/png;base64,')), true);
            $size = getimagesizefromstring((string) $png);

            return $request->url() === 'https://openai.invalid/v1/images/edits'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer '.self::KEY)
                && $request['model'] === 'gpt-image-2.6-sunburst'
                && $request['size'] === '1536x1024'
                && $request['output_format'] === 'png'
                && $request['background'] === 'opaque'
                && $request['n'] === 1
                && str_contains($request['prompt'], 'black-and-white line art')
                && str_starts_with($image, 'data:image/png;base64,')
                && $size !== false && $size[0] === 1536 && $size[1] === 1024;
        });

        $dir = $this->storageRoot.'/'.$upload['id'];
        $this->assertFileEquals($this->fixture('logo.png'), $dir.'/original.bin', 'the upload is never overwritten');
        $this->assertFileExists($dir.'/redraw.png');
        $this->assertSame([], glob($dir.'/tmp-*'));

        $image = $this->get('/api/ai/redraw/'.$upload['id'].'?token='.$upload['token'])->assertOk();
        $this->assertSame('image/png', $image->headers->get('Content-Type'));
        $this->assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($image->getContent())[2]);
        $this->getJson('/api/ai/redraw/'.$upload['id'].'?token=wrong')->assertStatus(403);
    }

    public function test_padding_is_removed_so_the_redraw_keeps_the_original_proportions(): void
    {
        $this->enable();
        // 500x500 fits a 1024x1024 canvas exactly; 800x600 (4:3) is padded left/right on 1536x1024.
        $this->fakeOpenAi($this->fakePng(1536, 1024, [0.4, 0.4, 0.6, 0.6]));
        $upload = $this->upload('line-drawing.png'); // 800x600

        $response = $this->redraw($upload)->assertOk();
        $this->assertEqualsWithDelta(800 / 600, $response->json('width') / $response->json('height'), 0.01);
        $this->assertSame(1024, $response->json('height'));
        Http::assertSent(fn (Request $r) => $r['size'] === '1536x1024');

        // tall picture → portrait canvas
        $this->fakeOpenAi(null, 1024, 1536);
        $tall = $this->upload('rotated.jpg'); // shown as 200x400
        $this->redraw($tall)->assertOk();
        Http::assertSent(fn (Request $r) => $r['size'] === '1024x1536');
    }

    public function test_user_can_trace_either_the_redraw_or_the_original(): void
    {
        $this->enable();
        $this->fakeOpenAi(); // a single centred rectangle
        $upload = $this->upload('logo.png'); // ring + square + triangle
        $this->redraw($upload)->assertOk();

        $fromRedraw = $this->convert($upload, ['source' => 'redraw']);
        $this->assertSame(1, $fromRedraw['stats']['paths'], 'the redrawn rectangle is what was traced');
        $w = $fromRedraw['stats']['pixel_width'];
        $h = $fromRedraw['stats']['pixel_height'];
        $this->assertTrue($this->isFilled($fromRedraw['svg'], 0.5 * $w, 0.5 * $h));
        $this->assertFalse($this->isFilled($fromRedraw['svg'], 0.1 * $w, 0.1 * $h));
        $this->assertStringContainsString('AI-redrawn', implode(' ', $fromRedraw['warnings']));
        $this->assertStringContainsString('width="150mm" height="100mm"', $fromRedraw['svg']);

        $fromOriginal = $this->convert($upload, ['source' => 'original']);
        $this->assertSame(3, $fromOriginal['stats']['paths'], 'the original is still available and unchanged');
        $this->assertStringNotContainsString('AI-redrawn', implode(' ', $fromOriginal['warnings']));
        $this->assertNotSame($fromRedraw['version'], $fromOriginal['version']);

        $this->assertSame(3, $this->convert($upload)['stats']['paths'], 'the default source is the original');
    }

    public function test_redrawing_again_replaces_the_redraw_and_is_not_served_from_a_stale_cache(): void
    {
        $this->enable();
        $upload = $this->upload('logo.png');

        $this->fakeOpenAi($this->fakePng(1536, 1024, [0.1, 0.1, 0.3, 0.9]));
        $first = $this->redraw($upload)->assertOk()->json('version');
        $a = $this->convert($upload, ['source' => 'redraw']);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeOpenAi($this->fakePng(1536, 1024, [0.7, 0.1, 0.9, 0.9]));
        $second = $this->redraw($upload)->assertOk()->json('version');
        $b = $this->convert($upload, ['source' => 'redraw']);

        $this->assertNotSame($first, $second);
        $w = $b['stats']['pixel_width'];
        $h = $b['stats']['pixel_height'];
        $this->assertTrue($this->isFilled($a['svg'], 0.2 * $w, 0.5 * $h));
        $this->assertFalse($this->isFilled($b['svg'], 0.2 * $w, 0.5 * $h), 'the old redraw is not traced again');
        $this->assertTrue($this->isFilled($b['svg'], 0.8 * $w, 0.5 * $h));
    }

    public function test_requires_the_conversion_token_before_any_paid_call(): void
    {
        $this->enable();
        $this->fakeOpenAi();
        $a = $this->upload('logo.png');
        $b = $this->upload('logo.png');

        $this->postJson('/api/ai/redraw', ['id' => $a['id'], 'token' => $b['token']])->assertStatus(403);
        $this->postJson('/api/ai/redraw', ['id' => $a['id']])->assertStatus(403);
        $this->postJson('/api/ai/redraw', [])->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_provider_failures_are_reported_cleanly_and_leave_everything_intact(): void
    {
        $this->enable();
        $upload = $this->upload('logo.png');
        $cases = [
            'key rejected' => [Http::response(['error' => ['message' => 'Incorrect API key provided: '.self::KEY, 'code' => 'invalid_api_key']], 401), 503],
            'rate limited' => [Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429), 503],
            'moderation' => [Http::response(['error' => ['type' => 'image_generation_user_error', 'code' => 'moderation_blocked']], 400), 422],
            'server error' => [Http::response('upstream exploded', 500), 503],
            'no image in answer' => [Http::response(['data' => []]), 502],
            'not base64' => [Http::response(['data' => [['b64_json' => '!!! not base64 !!!']]]), 502],
            'not an image' => [Http::response(['data' => [['b64_json' => base64_encode('<svg onload="alert(1)"/>')]]]), 502],
            'html pretending' => [Http::response(['data' => [['b64_json' => base64_encode("\x89PNG\r\n\x1a\n<?php system('id'); ?>")]]]), 502],
            'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 503],
        ];
        foreach ($cases as $label => [$fake, $status]) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['https://openai.invalid/v1/images/edits' => $fake]);

            $response = $this->redraw($upload);
            $response->assertStatus($status)->assertJsonPath('code', 'ai_unavailable');
            $this->assertStringNotContainsString(self::KEY, $response->getContent(), $label);
            $this->assertStringNotContainsString($this->storageRoot, $response->getContent(), $label);
            $this->assertFileDoesNotExist($this->storageRoot.'/'.$upload['id'].'/redraw.png', $label);
            $this->assertSame([], glob($this->storageRoot.'/'.$upload['id'].'/tmp-*'), $label);
        }
        $this->assertFileEquals($this->fixture('logo.png'), $this->storageRoot.'/'.$upload['id'].'/original.bin');
        $this->assertSame(3, $this->convert($upload)['stats']['paths'], 'normal conversion still works');
    }

    public function test_a_failed_redraw_keeps_the_previous_redraw(): void
    {
        $this->enable();
        $this->fakeOpenAi();
        $upload = $this->upload('logo.png');
        $this->redraw($upload)->assertOk();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://openai.invalid/v1/images/edits' => Http::response('nope', 500)]);
        $this->redraw($upload)->assertStatus(503);

        $this->assertSame(1, $this->convert($upload, ['source' => 'redraw'])['stats']['paths']);
    }
}
