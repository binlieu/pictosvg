<?php

namespace Tests\Feature;

use App\Services\Svg\SvgValidationService;
use App\Support\TemporaryFiles\ConversionStore;
use Tests\TestCase;

/** EXPORT-003 / SYS-002 / AI-001: download authorization, sizing, expiry and AI gating. */
class DownloadAndLifecycleTest extends TestCase
{
    private function url(array $upload, array $query = []): string
    {
        return '/api/svg/download/'.$upload['id'].'?'.http_build_query(['token' => $upload['token']] + $query);
    }

    public function test_download_serves_the_exact_previewed_svg_as_a_safe_attachment(): void
    {
        $upload = $this->upload('logo.png', 'My Logo (final).png');
        $result = $this->convert($upload);

        $response = $this->get($this->url($upload, ['version' => $result['version']]));
        $response->assertOk();
        $this->assertSame($result['svg'], $response->getContent(), 'download is byte-identical to the preview');
        $this->assertStringStartsWith('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="My-Logo-final-pictosvg.svg"', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_download_can_be_resized_without_changing_geometry(): void
    {
        $upload = $this->upload('logo.png');
        $result = $this->convert($upload);

        $svg = $this->get($this->url($upload, ['width' => 3, 'unit' => 'in']))->assertOk()->getContent();
        $this->assertStringContainsString('width="3in" height="2in"', $svg);
        $stats = app(SvgValidationService::class)->validate($svg);
        $this->assertSame($result['stats']['nodes'], $stats['nodes']);
        $strip = fn (string $s) => preg_replace('/ (width|height)="[^"]*"/', '', $s, 2);
        $this->assertSame($strip($result['svg']), $strip($svg));

        $mm = $this->get($this->url($upload, ['width' => 76.2, 'unit' => 'mm']))->assertOk()->getContent();
        $this->assertStringContainsString('width="76.2mm" height="50.8mm"', $mm);
    }

    public function test_download_requires_the_matching_token(): void
    {
        $a = $this->upload('logo.png');
        $b = $this->upload('logo.png');
        $this->convert($a);
        $this->convert($b);

        $this->getJson('/api/svg/download/'.$a['id'])->assertStatus(403);
        $this->getJson('/api/svg/download/'.$a['id'].'?token='.$b['token'])->assertStatus(403)->assertJsonPath('code', 'invalid_token');
        $this->getJson('/api/svg/download/'.$a['id'].'?token[]=x')->assertStatus(403);
        // an encoded traversal attempt never reaches a file: it is not a valid id (or route)
        $this->assertContains($this->getJson('/api/svg/download/..%2F..%2Fetc?token='.$a['token'])->status(), [403, 404]);
        $this->getJson('/api/svg/download/..?token='.$a['token'])->assertStatus(403);
        $this->getJson($this->url($a, ['version' => '../meta.json']))->assertStatus(422);
        $this->getJson($this->url($a, ['version' => str_repeat('0', 12)]))->assertStatus(404);
        $this->getJson($this->url($a, ['width' => 0, 'unit' => 'mm']))->assertStatus(422);
        $this->getJson($this->url($a, ['width' => 0.00001, 'unit' => 'mm']))->assertStatus(422);
        $this->getJson($this->url($a, ['width' => 5, 'unit' => 'px']))->assertStatus(422);
    }

    public function test_download_before_conversion_is_not_found(): void
    {
        $this->getJson($this->url($this->upload('logo.png')))->assertStatus(404);
    }

    public function test_expired_conversions_are_rejected_and_cleaned_up(): void
    {
        $old = $this->upload('logo.png');
        $fresh = $this->upload('logo.png');
        $this->convert($old);
        touch($this->storageRoot.'/'.$old['id'].'/meta.json', time() - 61 * 60);

        // an unrelated directory inside the storage root must never be touched
        mkdir($this->storageRoot.'/keep-me');
        touch($this->storageRoot.'/keep-me', time() - 86400);

        $this->artisan('pictosvg:cleanup')->expectsOutput('Removed 1 expired conversion(s).')->assertExitCode(0);
        $this->assertDirectoryDoesNotExist($this->storageRoot.'/'.$old['id']);
        $this->assertDirectoryExists($this->storageRoot.'/'.$fresh['id']);
        $this->assertDirectoryExists($this->storageRoot.'/keep-me');

        $this->postJson('/api/svg/convert', ['id' => $old['id'], 'token' => $old['token']])->assertStatus(404)->assertJsonPath('code', 'expired');
        $this->getJson($this->url($old))->assertStatus(404);
    }

    public function test_expired_conversion_is_rejected_even_before_cleanup_runs(): void
    {
        $upload = $this->upload('logo.png');
        touch($this->storageRoot.'/'.$upload['id'].'/meta.json', time() - 61 * 60);
        $this->postJson('/api/svg/convert', ['id' => $upload['id'], 'token' => $upload['token']])->assertStatus(404);
        $this->assertDirectoryDoesNotExist($this->storageRoot.'/'.$upload['id']);
    }

    public function test_only_a_bounded_number_of_result_versions_is_kept(): void
    {
        $upload = $this->upload('logo.png');
        foreach (range(1, 8) as $i) {
            $this->convert($upload, ['width' => 100 + $i]);
        }
        $this->assertLessThanOrEqual(6, count(glob($this->storageRoot.'/'.$upload['id'].'/result-*.svg')));
        $this->assertCount(1, glob($this->storageRoot.'/'.$upload['id'].'/bitmap-*.pbm'));
        $this->assertSame([], glob($this->storageRoot.'/'.$upload['id'].'/tmp-*'));
    }

    public function test_tokens_depend_on_the_application_key(): void
    {
        $store = new ConversionStore($this->storageRoot, 60, 'another-key');
        $upload = $this->upload('logo.png');
        $this->assertNotSame($upload['token'], $store->tokenFor($upload['id']));
    }

    public function test_home_page_renders_the_three_steps_without_ai(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        foreach (['PicToSVG', 'Upload Image', 'Convert to SVG', 'Download SVG', 'Engrave', 'Outline Cut', 'Line Art', 'Optimize Smooth Curves', 'Advanced Settings', 'AI Redraw is not set up'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('secret', $html);
        $this->assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//#', $html, 'assets are same-origin relative URLs');
    }
}
