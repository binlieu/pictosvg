<?php

namespace Tests\Feature;

use App\Services\Svg\PathGeometry;
use App\Services\Svg\SvgValidationService;
use Tests\TestCase;

/** TRACE-001 / OPT-001 / EXPORT-001: real conversions of the fixtures (no mocks). */
class ConversionTest extends TestCase
{
    public function test_logo_converts_with_defaults_to_a_real_sized_vector(): void
    {
        $result = $this->convert($this->upload('logo.png'));
        $svg = $result['svg'];

        $stats = app(SvgValidationService::class)->validate($svg);
        $this->assertSame('potrace', $result['engine']);
        $this->assertSame(3, $stats['paths'], 'ring, square, triangle');
        $this->assertSame(4, $stats['subpaths'], 'the ring keeps its hole');
        $this->assertSame($stats['paths'], $result['stats']['paths']);
        $this->assertSame($stats['nodes'], $result['stats']['nodes']);
        $this->assertSame(strlen($svg), $result['stats']['bytes']);

        // physical size: 150 mm default width, height from the 600x400 aspect ratio
        $this->assertStringContainsString('width="150mm" height="100mm"', $svg);
        $this->assertEqualsWithDelta(600 / 400, $stats['pixel_width'] / $stats['pixel_height'], 0.002);

        // real vectors only
        $this->assertStringNotContainsString('<image', $svg);
        $this->assertStringNotContainsString('base64', $svg);
        $this->assertStringNotContainsString('transform', $svg);
        $this->assertMatchesRegularExpression('/c-?[\d.]/', $svg, 'contains cubic Bézier curves');
        // a ring, a square and a triangle need very few nodes when curves are fitted well
        $this->assertLessThan(60, $stats['nodes']);
    }

    public function test_holes_and_shapes_are_preserved(): void
    {
        $result = $this->convert($this->upload('logo.png'));
        $k = $result['stats']['pixel_width'] / 600; // fixture px → viewBox units
        $at = fn (float $x, float $y) => $this->isFilled($result['svg'], $x * $k, $y * $k);

        $this->assertFalse($at(150, 200), 'centre of the ring is a hole');
        $this->assertTrue($at(150, 110), 'ring body (top)');
        $this->assertTrue($at(60, 200), 'ring body (left)');
        $this->assertTrue($at(370, 150), 'square');
        $this->assertTrue($at(510, 270), 'triangle');
        $this->assertFalse($at(280, 200), 'white space between shapes');
        $this->assertFalse($at(470, 200), 'outside the triangle slope');
        $this->assertFalse($at(10, 10), 'background');
    }

    public function test_sharp_corners_survive_default_smoothing(): void
    {
        $result = $this->convert($this->upload('logo.png'));
        $k = $result['stats']['pixel_width'] / 600;
        // The square's corners are at (320,100) … (420,200). A point 3px inside each corner
        // must be filled and a point 3px outside must not: smoothing may not round them off.
        foreach ([[320, 100, 1, 1], [420, 100, -1, 1], [420, 200, -1, -1], [320, 200, 1, -1]] as [$x, $y, $dx, $dy]) {
            $this->assertTrue($this->isFilled($result['svg'], ($x + 3 * $dx) * $k, ($y + 3 * $dy) * $k), "inside corner {$x},{$y}");
            $this->assertFalse($this->isFilled($result['svg'], ($x - 3 * $dx) * $k, ($y - 3 * $dy) * $k), "outside corner {$x},{$y}");
        }
    }

    public function test_transparent_png_is_flattened_onto_white_and_keeps_its_hole(): void
    {
        $result = $this->convert($this->upload('transparent-logo.png'));
        $k = $result['stats']['pixel_width'] / 500;
        $this->assertTrue($this->isFilled($result['svg'], 250 * $k, 100 * $k), 'ring body');
        $this->assertFalse($this->isFilled($result['svg'], 250 * $k, 200 * $k), 'transparent gap inside the ring');
        $this->assertTrue($this->isFilled($result['svg'], 250 * $k, 250 * $k), 'bar in the middle');
        $this->assertFalse($this->isFilled($result['svg'], 10 * $k, 10 * $k), 'transparent background is not filled');
        $this->assertSame(2, $result['stats']['paths']);
    }

    public function test_light_artwork_on_transparent_background_traces_the_visible_shape(): void
    {
        $result = $this->convert($this->upload('light-on-transparent.png'));
        $k = $result['stats']['pixel_width'] / 400;
        $this->assertTrue($this->isFilled($result['svg'], 200 * $k, 200 * $k), 'star body');
        $this->assertFalse($this->isFilled($result['svg'], 20 * $k, 20 * $k));
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_noise_removal_drops_specks_and_zero_keeps_them(): void
    {
        // noisy-logo.png = the 3 logo shapes + four 1–2 px specks + one deliberate 3x3 px dot
        $upload = $this->upload('noisy-logo.png');

        $default = $this->convert($upload);
        $this->assertSame(4, $default['stats']['paths'], 'default removes the tiny specks but keeps the 3x3 dot (it could be a full stop)');

        $strong = $this->convert($upload, ['noise' => 10]);
        $this->assertSame(3, $strong['stats']['paths'], 'maximum noise removal leaves only the three shapes');
        $k = $strong['stats']['pixel_width'] / 600;
        $this->assertFalse($this->isFilled($strong['svg'], 150 * $k, 200 * $k), 'the ring hole is not treated as noise');

        $off = $this->convert($upload, ['noise' => 0, 'smoothing' => 0]);
        $this->assertGreaterThan(4, $off['stats']['paths'], 'with noise removal off the specks are traced');
    }

    public function test_optimisation_reduces_measured_nodes_without_changing_the_shape(): void
    {
        $upload = $this->upload('illustration.png');
        $optimised = $this->convert($upload);
        $raw = $this->convert($upload, ['smooth_curves' => false]);

        $this->assertSame($raw['stats']['nodes'], $raw['stats']['nodes_before']);
        $this->assertSame($raw['stats']['nodes'], $optimised['stats']['nodes_before'], 'before-count is the measured raw trace');
        $this->assertLessThan($raw['stats']['nodes'] * 0.8, $optimised['stats']['nodes']);
        $this->assertSame($raw['stats']['paths'], $optimised['stats']['paths']);

        // Same fill result on a grid of sample points (allowing only edge-adjacent differences).
        $w = $optimised['stats']['pixel_width'];
        $h = $optimised['stats']['pixel_height'];
        $differences = 0;
        $samples = 0;
        for ($x = 0.025; $x < 1; $x += 0.05) {
            for ($y = 0.025; $y < 1; $y += 0.05) {
                $samples++;
                $differences += (int) ($this->isFilled($raw['svg'], $x * $w, $y * $h) !== $this->isFilled($optimised['svg'], $x * $w, $y * $h));
            }
        }
        $this->assertLessThanOrEqual(2, $differences, "{$differences} of {$samples} sample points changed");
    }

    public function test_higher_smoothing_never_increases_nodes_and_keeps_text_legible_shapes(): void
    {
        $upload = $this->upload('text.png');
        $low = $this->convert($upload, ['smoothing' => 1]);
        $high = $this->convert($upload, ['smoothing' => 10]);
        $this->assertLessThanOrEqual($low['stats']['nodes'], $high['stats']['nodes']);
        // "PicToSVG" headline alone has 8 glyphs; the whole fixture has well over 100 glyph shapes.
        $this->assertGreaterThan(100, $high['stats']['paths']);
        // Default settings keep the dots of the 18 px small print (i, j, colon): visual review
        // counted 110 glyph shapes; losing the small dots drops this to 105.
        $this->assertGreaterThanOrEqual(109, $this->convert($upload)['stats']['paths']);
        // letter counters (holes) survive: far more contours than shapes
        $subpaths = array_sum(array_map('count', $this->shapes($high['svg'])));
        $this->assertGreaterThan($high['stats']['paths'] + 20, $subpaths);
    }

    public function test_outline_cut_produces_only_closed_stroked_contours(): void
    {
        $result = $this->convert($this->upload('logo.png'), ['preset' => 'outline']);
        $this->assertStringContainsString('fill="none" stroke="#000000"', $result['svg']);
        $this->assertStringNotContainsString('<rect', $result['svg']);
        $contours = 0;
        $seen = [];
        foreach ($this->shapes($result['svg']) as $shape) {
            foreach ($shape as $subpath) {
                $contours++;
                $this->assertTrue($subpath['closed'], 'every cut contour is closed');
                $key = PathGeometry::serialize([$subpath], 0);
                $this->assertArrayNotHasKey($key, $seen, 'no duplicated cut contour');
                $seen[$key] = true;
            }
        }
        $this->assertSame(4, $contours, 'ring outside + ring inside + square + triangle');
        $this->assertSame(0, app(SvgValidationService::class)->validate($result['svg'])['open_subpaths']);
        $this->assertNotEmpty($result['warnings'], 'cut mode always carries the check-before-cutting note');
    }

    public function test_line_art_traces_thin_strokes_and_says_it_is_not_centerline(): void
    {
        $result = $this->convert($this->upload('line-drawing.png'), ['preset' => 'lineart']);
        $k = $result['stats']['pixel_width'] / 800;
        $this->assertTrue($this->isFilled($result['svg'], 250 * $k, 120 * $k), 'outer circle stroke');
        $this->assertFalse($this->isFilled($result['svg'], 250 * $k, 150 * $k), 'gap between circles');
        $this->assertTrue($this->isFilled($result['svg'], 600 * $k, 100 * $k), 'thin hatch line');
        $this->assertFalse($this->isFilled($result['svg'], 600 * $k, 110 * $k), 'gap between hatch lines');
        $this->assertStringContainsString('centerline', implode(' ', $result['warnings']));
    }

    public function test_photo_like_image_converts_to_an_approximate_vector(): void
    {
        $result = $this->convert($this->upload('vehicle-photo.jpg'));
        app(SvgValidationService::class)->validate($result['svg']);
        $this->assertGreaterThan(0, $result['stats']['paths']);
        $this->assertLessThan(400, $result['stats']['paths'], 'sensor noise is not traced as hundreds of specks');
        $this->assertStringContainsString('width="150mm" height="96mm"', $result['svg']);
    }

    public function test_rotated_jpeg_is_traced_upright(): void
    {
        $result = $this->convert($this->upload('rotated.jpg'));
        $this->assertLessThan($result['stats']['pixel_height'], $result['stats']['pixel_width']);
        $this->assertEqualsWithDelta(0.5, $result['stats']['pixel_width'] / $result['stats']['pixel_height'], 0.01);
    }

    public function test_advanced_settings_invert_units_and_background(): void
    {
        $upload = $this->upload('logo.png');
        $result = $this->convert($upload, ['invert' => true, 'unit' => 'in', 'width' => 6, 'transparent_background' => false, 'threshold' => 50, 'corners' => 10, 'tolerance' => 0.2, 'speck' => 4, 'contrast' => 10]);
        $k = $result['stats']['pixel_width'] / 600;
        $this->assertStringContainsString('width="6in" height="4in"', $result['svg']);
        $this->assertStringContainsString('<rect', $result['svg']);
        $this->assertTrue($this->isFilled($result['svg'], 10 * $k, 10 * $k), 'inverted: background is now black');
        $this->assertFalse($this->isFilled($result['svg'], 370 * $k, 150 * $k), 'inverted: the square is now empty');
    }

    public function test_blank_image_gives_an_actionable_error(): void
    {
        $tmp = sys_get_temp_dir().'/pts-blank-'.bin2hex(random_bytes(4)).'.png';
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagepng($im, $tmp);
        copy($tmp, $this->fixture('__blank.png'));
        try {
            $upload = $this->upload('__blank.png');
        } finally {
            @unlink($this->fixture('__blank.png'));
            @unlink($tmp);
        }
        $this->postJson('/api/svg/convert', ['id' => $upload['id'], 'token' => $upload['token']])
            ->assertStatus(422)->assertJsonPath('code', 'conversion_failed');
    }

    public function test_validation_and_authorization_of_convert_requests(): void
    {
        $a = $this->upload('logo.png');
        $b = $this->upload('logo.png');
        $this->postJson('/api/svg/convert', ['id' => $a['id'], 'token' => $b['token']])->assertStatus(403)->assertJsonPath('code', 'invalid_token');
        $this->postJson('/api/svg/convert', ['id' => str_repeat('a', 32), 'token' => str_repeat('b', 64)])->assertStatus(403);
        $this->postJson('/api/svg/convert', ['id' => '../'.substr($a['id'], 3), 'token' => $a['token']])->assertStatus(422);
        $this->postJson('/api/svg/convert', ['id' => $a['id'], 'token' => $a['token'], 'preset' => 'gcode'])->assertStatus(422);
        $this->postJson('/api/svg/convert', ['id' => $a['id'], 'token' => $a['token'], 'detail' => 99])->assertStatus(422);
        $this->postJson('/api/svg/convert', ['id' => $a['id'], 'token' => $a['token'], 'width' => -5])->assertStatus(422);
        $this->postJson('/api/svg/convert', ['id' => $a['id'], 'token' => $a['token'], 'width' => 0.00001])->assertStatus(422);
        $this->postJson('/api/svg/convert', ['id' => $a['id']])->assertStatus(422);
        $this->postJson('/api/svg/convert', ['id' => $a['id'], 'token' => $a['token'], 'unit' => 'px; rm -rf'])->assertStatus(422);
    }

    public function test_optimize_endpoint_requires_a_previous_conversion(): void
    {
        $upload = $this->upload('logo.png');
        $body = ['id' => $upload['id'], 'token' => $upload['token']];
        $this->postJson('/api/svg/optimize', $body)->assertStatus(409)->assertJsonPath('code', 'conversion_failed');
        $this->convert($upload);
        $this->postJson('/api/svg/optimize', $body + ['smoothing' => 3])->assertOk()->assertJsonPath('stats.unit', 'mm');
    }

    public function test_overly_complex_traces_are_refused_before_they_exhaust_memory(): void
    {
        $upload = $this->upload('text.png');
        config(['pictosvg.max_trace_bytes' => 2000]);
        $this->postJson('/api/svg/convert', ['id' => $upload['id'], 'token' => $upload['token']])
            ->assertStatus(422)->assertJsonPath('code', 'conversion_failed');
        $this->assertSame([], glob($this->storageRoot.'/'.$upload['id'].'/tmp-*'), 'no temp files left behind');
    }

    public function test_missing_tracer_binary_is_reported_without_leaking_paths(): void
    {
        $upload = $this->upload('logo.png');
        config(['pictosvg.potrace_bin' => '/nonexistent/secret/dir/potrace']);
        $response = $this->postJson('/api/svg/convert', ['id' => $upload['id'], 'token' => $upload['token']]);
        $response->assertStatus(503)->assertJsonPath('code', 'tool_missing');
        $this->assertStringNotContainsString('/nonexistent', $response->getContent());
        $this->assertStringNotContainsString($this->storageRoot, $response->getContent());
    }
}
