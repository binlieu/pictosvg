<?php

namespace Tests;

use App\Services\Svg\PathGeometry;
use DOMDocument;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/pictosvg-tests-'.bin2hex(random_bytes(6));
        // No test may reach the real AI service, whatever .env or the shell contains.
        config(['pictosvg.storage_path' => $this->storageRoot, 'pictosvg.ai.openai_api_key' => null, 'pictosvg.ai.base_url' => 'https://openai.invalid/v1']);
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    protected function fixture(string $name): string
    {
        return __DIR__.'/fixtures/'.$name;
    }

    /** Uploads a COPY of a fixture (uploads are moved) and returns the JSON body. */
    protected function upload(string $fixture, ?string $clientName = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pts');
        copy($this->fixture($fixture), $tmp);
        $response = $this->post('/api/images/upload', [
            'image' => new UploadedFile($tmp, $clientName ?? $fixture, null, null, true),
        ], ['Accept' => 'application/json']);
        $response->assertStatus(201);

        return $response->json();
    }

    protected function convert(array $upload, array $settings = []): array
    {
        $response = $this->postJson('/api/svg/convert', ['id' => $upload['id'], 'token' => $upload['token']] + $settings);
        $response->assertOk();

        return $response->json();
    }

    /** @return list<list<array>> shapes (each a list of subpaths) parsed from an exported SVG */
    protected function shapes(string $svg): array
    {
        $doc = new DOMDocument;
        $this->assertTrue($doc->loadXML($svg, LIBXML_NONET), 'SVG must be well-formed XML');
        $shapes = [];
        foreach ($doc->getElementsByTagName('path') as $path) {
            $shapes[] = PathGeometry::parse($path->getAttribute('d'));
        }

        return $shapes;
    }

    /** Non-zero winding fill test of the whole document at a viewBox point. */
    protected function isFilled(string $svg, float $x, float $y): bool
    {
        foreach ($this->shapes($svg) as $shape) {
            $winding = 0;
            foreach ($shape as $subpath) {
                $pts = PathGeometry::flatten($subpath, 12);
                $n = count($pts);
                for ($i = 0; $i < $n; $i++) {
                    [$x1, $y1] = $pts[$i];
                    [$x2, $y2] = $pts[($i + 1) % $n];
                    if (($y1 <= $y) !== ($y2 <= $y)) {
                        $xi = $x1 + ($y - $y1) * ($x2 - $x1) / ($y2 - $y1);
                        if ($xi > $x) {
                            $winding += $y2 > $y1 ? 1 : -1;
                        }
                    }
                }
            }
            if ($winding !== 0) {
                return true;
            }
        }

        return false;
    }
}
