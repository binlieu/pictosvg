<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** UPLOAD-001 / SYS-001: content-based validation and safe temporary storage. */
class UploadTest extends TestCase
{
    private function postFile(string $path, string $name)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pts');
        copy($path, $tmp);

        return $this->post('/api/images/upload', ['image' => new UploadedFile($tmp, $name, null, null, true)], ['Accept' => 'application/json']);
    }

    private function postContents(string $contents, string $name)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pts');
        file_put_contents($tmp, $contents);

        return $this->postFile($tmp, $name);
    }

    public function test_accepts_png_jpeg_and_webp_and_reports_dimensions(): void
    {
        foreach (['logo.png' => ['image/png', 600, 400], 'vehicle-photo.jpg' => ['image/jpeg', 1000, 640], 'logo.webp' => ['image/webp', 600, 400]] as $file => [$mime, $w, $h]) {
            $json = $this->upload($file);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $json['id']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $json['token']);
            $this->assertSame([$mime, $w, $h, $file], [$json['mime'], $json['width'], $json['height'], $json['name']]);
            $this->assertFileExists($this->storageRoot.'/'.$json['id'].'/original.bin');
            $this->assertFileEquals($this->fixture($file), $this->storageRoot.'/'.$json['id'].'/original.bin');
        }
    }

    public function test_reports_orientation_corrected_dimensions(): void
    {
        $json = $this->upload('rotated.jpg');
        $this->assertSame([200, 400], [$json['width'], $json['height']]);
    }

    public function test_png_orientation_metadata_is_honoured_like_jpeg(): void
    {
        // Insert an eXIf chunk (Orientation = 6) after IHDR of the 600x400 logo.
        $png = file_get_contents($this->fixture('logo.png'));
        $tiff = "MM\x00\x2a".pack('N', 8).pack('n', 1).pack('nnNnn', 0x0112, 3, 1, 6, 0).pack('N', 0);
        $chunk = pack('N', strlen($tiff)).'eXIf'.$tiff.pack('N', crc32('eXIf'.$tiff));
        $response = $this->postContents(substr($png, 0, 33).$chunk.substr($png, 33), 'rotated.png');
        $response->assertStatus(201);
        $this->assertSame([400, 600], [$response->json('width'), $response->json('height')]);

        $result = $this->postJson('/api/svg/convert', ['id' => $response->json('id'), 'token' => $response->json('token')])->assertOk()->json();
        $this->assertEqualsWithDelta(400 / 600, $result['stats']['pixel_width'] / $result['stats']['pixel_height'], 0.01, 'no stretching');
        $this->assertStringContainsString('width="150mm" height="225mm"', $result['svg']);
    }

    public function test_rejects_non_images_regardless_of_extension(): void
    {
        $cases = [
            'text renamed to png' => ['hello world', 'fake.png'],
            'php with png extension' => ["<?php system(\$_GET['c']); ?>", 'shell.png'],
            'php with gif magic bytes' => ['GIF89a<?php echo 1; ?>', 'shell.jpg'],
            'svg with script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg'],
            'truncated png header' => ["\x89PNG\r\n\x1a\n", 'broken.png'],
            'empty' => ['', 'empty.png'],
        ];
        foreach ($cases as $label => [$contents, $name]) {
            $response = $this->postContents($contents, $name);
            $this->assertContains($response->status(), [422], $label);
            $this->assertContains($response->json('code'), ['unsupported_image', 'image_too_large'], $label);
        }
        $this->assertSame([], glob($this->storageRoot.'/*') ?: [], 'nothing is stored for rejected uploads');
    }

    public function test_rejects_missing_file(): void
    {
        $this->postJson('/api/images/upload', [])->assertStatus(422)->assertJsonPath('code', 'unsupported_image');
    }

    public function test_enforces_decoded_pixel_limit_and_byte_limit(): void
    {
        config(['pictosvg.max_pixels' => 100_000]);
        $this->postFile($this->fixture('logo.png'), 'logo.png')->assertStatus(422)->assertJsonPath('code', 'image_too_large');

        config(['pictosvg.max_pixels' => 40_000_000, 'pictosvg.max_upload_mb' => 0]);
        $this->postFile($this->fixture('logo.png'), 'logo.png')->assertStatus(422)->assertJsonPath('code', 'image_too_large');
    }

    public function test_client_filename_is_never_used_as_a_path(): void
    {
        $json = $this->upload('logo.png', '../../../etc/<b>pass wd</b>.png');
        $this->assertStringNotContainsString('/', $json['name']);
        $this->assertStringNotContainsString('<', $json['name']);
        $entries = array_values(array_diff(scandir($this->storageRoot.'/'.$json['id']), ['.', '..']));
        sort($entries);
        $this->assertSame(['meta.json', 'original.bin'], $entries);
    }
}
