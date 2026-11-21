<?php

namespace Tests\Feature;

use App\Exceptions\PicToSvgException;
use App\Services\Ai\ClaudeSettingsAdvisor;
use App\Services\Ai\SettingsAdvisor;
use Tests\TestCase;

/**
 * AI-002: optional "AI Suggest Settings". The Anthropic API is never called in tests;
 * a fake advisor stands in at the SettingsAdvisor boundary.
 */
class AiSuggestTest extends TestCase
{
    /** @param array<string, mixed>|callable $reply */
    private function fakeAdvisor(array|callable $reply, bool $configured = true): object
    {
        $fake = new class($reply, $configured) implements SettingsAdvisor
        {
            public array $calls = [];

            public function __construct(private $reply, private bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function suggest(string $originalPng, string $bitmapPng, array $current): array
            {
                $this->calls[] = compact('originalPng', 'bitmapPng', 'current');

                return is_callable($this->reply) ? ($this->reply)() : $this->reply;
            }
        };
        $this->app->instance(SettingsAdvisor::class, $fake);

        return $fake;
    }

    public function test_feature_is_off_without_a_key_and_conversion_is_unaffected(): void
    {
        config(['pictosvg.ai.anthropic_api_key' => null]);
        $this->getJson('/api/ai/status')->assertOk()->assertJsonPath('suggest_configured', false);

        $upload = $this->upload('logo.png');
        $this->postJson('/api/ai/suggest', ['id' => $upload['id'], 'token' => $upload['token']])
            ->assertStatus(503)->assertJsonPath('code', 'ai_unavailable');
        $this->assertStringContainsString('disabled', $this->get('/')->getContent());
        $this->convert($upload);
    }

    public function test_key_presence_enables_the_button_and_never_reaches_the_page(): void
    {
        config(['pictosvg.ai.anthropic_api_key' => 'sk-ant-test-not-a-real-key']);
        $this->getJson('/api/ai/status')->assertOk()->assertJsonPath('suggest_configured', true);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('"aiConfigured":true', $html);
        $this->assertStringContainsString('Sends a reduced copy of your picture', $html);
        $this->assertStringNotContainsString('sk-ant-test', $html);
    }

    public function test_suggestion_is_built_from_the_picture_and_the_current_bitmap(): void
    {
        $fake = $this->fakeAdvisor([
            'preset' => 'lineart', 'detail' => 9, 'smoothing' => 5, 'noise' => 3, 'invert' => false,
            'threshold_auto' => false, 'threshold' => 62, 'contrast' => 15,
            'explanation' => 'Thin lines were breaking up, so the cut-off was raised.',
        ]);
        $upload = $this->upload('line-drawing.png');

        $response = $this->postJson('/api/ai/suggest', ['id' => $upload['id'], 'token' => $upload['token'], 'noise' => 8]);
        $response->assertOk()->assertExactJson([
            'settings' => [
                'preset' => 'lineart', 'detail' => 9, 'smoothing' => 5, 'noise' => 3, 'invert' => false,
                'threshold' => 62, 'contrast' => 15, 'outline_only' => false,
            ],
            'explanation' => 'Thin lines were breaking up, so the cut-off was raised.',
        ]);

        $this->assertCount(1, $fake->calls);
        $call = $fake->calls[0];
        foreach (['originalPng', 'bitmapPng'] as $key) {
            $info = getimagesizefromstring($call[$key]);
            $this->assertSame(IMAGETYPE_PNG, $info[2], "$key is a PNG");
            $this->assertLessThanOrEqual(1024, max($info[0], $info[1]), "$key is reduced before it leaves the server");
            $this->assertEqualsWithDelta(800 / 600, $info[0] / $info[1], 0.02);
        }
        $this->assertSame(8, $call['current']['noise'], 'the AI is told the settings currently in the UI');
        $this->assertTrue($call['current']['threshold_auto']);

        // the suggested settings are accepted as-is by the normal conversion
        $this->convert($upload, $response->json('settings'));
    }

    public function test_model_output_is_clamped_and_never_trusted(): void
    {
        $this->fakeAdvisor([
            'preset' => 'gcode; rm -rf /', 'detail' => 999, 'smoothing' => -4, 'noise' => 'lots', 'invert' => 'yes',
            'threshold_auto' => false, 'threshold' => 500, 'contrast' => -900,
            'explanation' => "<script>alert(1)</script>Looks\n\n fine".str_repeat(' x', 400),
            'width' => 99999, 'extra' => ['nested' => true],
        ]);
        $upload = $this->upload('logo.png');

        $json = $this->postJson('/api/ai/suggest', ['id' => $upload['id'], 'token' => $upload['token']])->assertOk()->json();
        $this->assertSame([
            'preset' => 'engrave', 'detail' => 10, 'smoothing' => 0, 'noise' => 5, 'invert' => false,
            'threshold' => 99, 'contrast' => -50, 'outline_only' => false,
        ], $json['settings']);
        $this->assertStringNotContainsString('<', $json['explanation']);
        $this->assertLessThanOrEqual(400, mb_strlen($json['explanation']));
        $this->convert($upload, $json['settings']);
    }

    public function test_auto_threshold_and_outline_preset_are_mapped(): void
    {
        $this->fakeAdvisor([
            'preset' => 'outline', 'detail' => 6, 'smoothing' => 8, 'noise' => 7, 'invert' => true,
            'threshold_auto' => true, 'threshold' => 40, 'contrast' => 0, 'explanation' => 'ok',
        ]);
        $upload = $this->upload('logo.png');
        $settings = $this->postJson('/api/ai/suggest', ['id' => $upload['id'], 'token' => $upload['token']])->assertOk()->json('settings');
        $this->assertNull($settings['threshold']);
        $this->assertTrue($settings['outline_only']);
        $this->assertTrue($settings['invert']);
    }

    public function test_requires_the_conversion_token_and_reports_ai_failures_cleanly(): void
    {
        $fake = $this->fakeAdvisor(fn () => throw new PicToSvgException('The AI service could not be reached. Normal conversion still works.', 'ai_unavailable', 503));
        $a = $this->upload('logo.png');
        $b = $this->upload('logo.png');

        $this->postJson('/api/ai/suggest', ['id' => $a['id'], 'token' => $b['token']])->assertStatus(403);
        $this->postJson('/api/ai/suggest', ['id' => $a['id']])->assertStatus(422);
        $this->assertCount(0, $fake->calls, 'no paid call is made for an unauthorised request');

        $this->postJson('/api/ai/suggest', ['id' => $a['id'], 'token' => $a['token']])
            ->assertStatus(503)->assertJsonPath('code', 'ai_unavailable');
        $this->convert($a);
    }

    public function test_real_adapter_refuses_to_call_out_without_a_key(): void
    {
        config(['pictosvg.ai.anthropic_api_key' => '']);
        $this->expectException(PicToSvgException::class);
        app(ClaudeSettingsAdvisor::class)->suggest('x', 'y', []);
    }
}
