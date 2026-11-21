<?php

namespace Tests\Feature;

use App\Services\Svg\SvgValidationService;
use App\Services\Tracing\ColorPalette;
use DOMDocument;
use Tests\TestCase;

/** TRACE-002: colour preset — solid colours become filled vector layers in the same colours. */
class ColorConversionTest extends TestCase
{
    /** The visible fill colour at a viewBox point (layers are painted in document order), or null. */
    private function colourAt(string $svg, float $x, float $y): ?string
    {
        $doc = new DOMDocument;
        $doc->loadXML($svg, LIBXML_NONET);
        $colour = null;
        foreach ($doc->getElementsByTagName('g') as $group) {
            $layer = '<svg xmlns="http://www.w3.org/2000/svg">';
            foreach ($group->getElementsByTagName('path') as $path) {
                $layer .= '<path d="'.$path->getAttribute('d').'"/>';
            }
            if ($this->isFilled($layer.'</svg>', $x, $y)) {
                $colour = $group->getAttribute('fill');
            }
        }

        return $colour;
    }

    public function test_solid_colours_are_kept_exactly_and_in_the_right_places(): void
    {
        // illustration.png (800x800): cream #fdf6e3 background, dark ring #073642, orange star #cb4b16,
        // blue dot #268bd2 (0.2 % of the picture), four teal squares #2aa198 with cream holes.
        $result = $this->convert($this->upload('illustration.png'), ['preset' => 'color']);
        $svg = $result['svg'];
        $stats = app(SvgValidationService::class)->validate($svg);
        $k = $stats['pixel_width'] / 800;
        $at = fn (float $x, float $y) => $this->colourAt($svg, $x * $k, $y * $k);

        $this->assertSame(['#fdf6e3', '#073642', '#cb4b16', '#2aa198', '#268bd2'], $result['stats']['colors'], 'background first, then largest areas, small details last');
        $this->assertSame('#fdf6e3', $at(20, 20), 'background');
        $this->assertSame('#073642', $at(400, 120), 'ring');
        $this->assertSame('#cb4b16', $at(400, 300), 'star');
        $this->assertSame('#268bd2', $at(400, 420), 'small blue dot survives');
        $this->assertSame('#fdf6e3', $at(400, 388), 'cream circle around the dot');
        $this->assertSame('#2aa198', $at(70, 70), 'corner square');
        $this->assertSame('#fdf6e3', $at(110, 110), 'hole in the corner square shows the background');
        $this->assertSame('#fdf6e3', $at(300, 200), 'gap between ring and star');

        // real, sized vectors only
        $this->assertStringContainsString('width="150mm" height="150mm"', $svg);
        foreach (['<image', 'base64', 'transform', '<style', 'url('] as $needle) {
            $this->assertStringNotContainsString($needle, $svg);
        }
        $this->assertLessThan(400, $stats['nodes'], 'flat artwork needs few nodes');
        $this->assertLessThan($result['stats']['nodes_before'], $result['stats']['nodes']);
        $this->assertSame(0, $stats['open_subpaths']);
    }

    public function test_layers_overlap_so_neighbouring_colours_leave_no_gaps(): void
    {
        $result = $this->convert($this->upload('illustration.png'), ['preset' => 'color']);
        $w = $result['stats']['pixel_width'];
        $h = $result['stats']['pixel_height'];
        // Every sampled point is covered by some colour, including points on colour boundaries.
        $uncovered = 0;
        for ($x = 0.01; $x < 1; $x += 0.0245) {
            for ($y = 0.01; $y < 1; $y += 0.0245) {
                $uncovered += (int) ($this->colourAt($result['svg'], $x * $w, $y * $h) === null);
            }
        }
        $this->assertSame(0, $uncovered);
    }

    public function test_background_colour_can_be_left_out(): void
    {
        $result = $this->convert($this->upload('illustration.png'), ['preset' => 'color', 'transparent_background' => true]);
        $k = $result['stats']['pixel_width'] / 800;
        $this->assertSame(['#073642', '#cb4b16', '#2aa198', '#268bd2'], $result['stats']['colors']);
        $this->assertNull($this->colourAt($result['svg'], 20 * $k, 20 * $k), 'nothing is drawn where the background was');
        $this->assertNull($this->colourAt($result['svg'], 110 * $k, 110 * $k), 'background-coloured holes stay open');
        $this->assertSame('#cb4b16', $this->colourAt($result['svg'], 400 * $k, 300 * $k));
    }

    public function test_number_of_colours_can_be_limited(): void
    {
        $result = $this->convert($this->upload('illustration.png'), ['preset' => 'color', 'colors' => 3]);
        $this->assertSame(['#fdf6e3', '#073642', '#cb4b16'], $result['stats']['colors'], 'the three largest colours are kept');
        $this->assertNotEmpty($result['warnings']);
        $k = $result['stats']['pixel_width'] / 800;
        $this->assertContains($this->colourAt($result['svg'], 70 * $k, 70 * $k), ['#073642', '#cb4b16', '#fdf6e3'], 'the teal square is drawn in the nearest kept colour');
    }

    public function test_transparent_pictures_keep_transparency_and_have_no_hidden_layers(): void
    {
        $result = $this->convert($this->upload('transparent-logo.png'), ['preset' => 'color']);
        $k = $result['stats']['pixel_width'] / 500;
        $this->assertSame(['#1a1a1a'], $result['stats']['colors'], 'no invisible white layer under the artwork');
        $this->assertSame('#1a1a1a', $this->colourAt($result['svg'], 250 * $k, 100 * $k));
        $this->assertNull($this->colourAt($result['svg'], 10 * $k, 10 * $k), 'transparent stays empty');
        $this->assertNull($this->colourAt($result['svg'], 250 * $k, 200 * $k));
        $this->assertSame(2, $result['stats']['paths']);
    }

    public function test_photo_like_picture_converts_approximately_within_the_colour_limit(): void
    {
        $result = $this->convert($this->upload('vehicle-photo.jpg'), ['preset' => 'color', 'colors' => 6, 'noise' => 8]);
        app(SvgValidationService::class)->validate($result['svg']);
        $this->assertLessThanOrEqual(6, count($result['stats']['colors']));
        $this->assertGreaterThanOrEqual(4, count($result['stats']['colors']));
        // the car body is some shade of red
        $k = $result['stats']['pixel_width'] / 1000;
        [$r, $g, $b] = ColorPalette::rgb((string) $this->colourAt($result['svg'], 500 * $k, 380 * $k));
        $this->assertTrue($r > 140 && $g < 90 && $b < 90, "car body colour rgb($r,$g,$b)");
    }

    public function test_single_colour_picture_and_bad_parameters_are_reported(): void
    {
        $tmp = $this->fixture('__flat.png');
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        imagepng($im, $tmp);
        try {
            $flat = $this->upload('__flat.png');
        } finally {
            @unlink($tmp);
        }
        $body = ['id' => $flat['id'], 'token' => $flat['token'], 'preset' => 'color'];
        $this->postJson('/api/svg/convert', $body)->assertStatus(422)->assertJsonPath('code', 'conversion_failed');
        $this->postJson('/api/svg/convert', $body + ['colors' => 1])->assertStatus(422);
        $this->postJson('/api/svg/convert', $body + ['colors' => 99])->assertStatus(422);
        $this->assertSame([], glob($this->storageRoot.'/'.$flat['id'].'/tmp-*'), 'no temp files left behind');
    }

    public function test_colour_result_downloads_and_resizes_like_any_other(): void
    {
        $upload = $this->upload('illustration.png');
        $result = $this->convert($upload, ['preset' => 'color']);
        $svg = $this->get('/api/svg/download/'.$upload['id'].'?'.http_build_query(['token' => $upload['token'], 'version' => $result['version'], 'width' => 4, 'unit' => 'in']))->assertOk()->getContent();
        $this->assertStringContainsString('width="4in" height="4in"', $svg);
        $this->assertSame(substr_count($result['svg'], '<path'), substr_count($svg, '<path'));
        $this->assertStringContainsString('fill="#cb4b16"', $svg);
    }

    public function test_palette_merges_edge_blends_but_keeps_small_distinct_colours(): void
    {
        $palette = ColorPalette::choose([
            '#fdf6e3' => 104000, '#073642' => 25000, '#cb4b16' => 19000, '#2aa198' => 8900,
            '#268bd2' => 317,     // small but distinct
            '#4d6d70' => 120,     // blend of ring and background
            '#e7ac8a' => 94,      // blend of star and background
            '#113e49' => 84,      // a shade of the ring
            '#fefefe' => 3,       // stray pixels
        ], 12);
        $this->assertSame(['#fdf6e3', '#073642', '#cb4b16', '#2aa198', '#268bd2'], $palette);
        $this->assertSame(['#fdf6e3', '#073642'], ColorPalette::choose(['#fdf6e3' => 9, '#073642' => 5, '#cb4b16' => 4], 2));
        $this->assertSame([203, 75, 22], ColorPalette::rgb('#cb4b16'));
    }
}
