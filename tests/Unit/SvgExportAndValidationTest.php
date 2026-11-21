<?php

namespace Tests\Unit;

use App\Exceptions\PicToSvgException;
use App\Services\Svg\PathGeometry;
use App\Services\Svg\SvgExportService;
use App\Services\Svg\SvgValidationService;
use Tests\TestCase;

/** EXPORT-001/002: physical sizing, aspect ratio, and the safety allow-list. */
class SvgExportAndValidationTest extends TestCase
{
    private function build(array $overrides = []): string
    {
        $shape = PathGeometry::parse('M10 10 l100 0 0 50 -100 0 z M20 20 l0 30 30 0 0 -30 z');

        return (new SvgExportService)->build([$shape], $overrides + [
            'pixel_width' => 1500, 'pixel_height' => 1000, 'width' => 150, 'unit' => 'mm',
            'outline' => false, 'white_background' => false,
        ]);
    }

    public function test_150mm_export_has_matching_height_and_viewbox(): void
    {
        $svg = $this->build();
        $this->assertStringContainsString('width="150mm" height="100mm" viewBox="0 0 1500 1000"', $svg);
        $stats = (new SvgValidationService)->validate($svg);
        $this->assertSame(['paths' => 1, 'subpaths' => 2, 'nodes' => 8, 'open_subpaths' => 0], array_slice($stats, 0, 4));
        $this->assertSame('mm', $stats['unit']);
    }

    public function test_inches_and_non_round_aspect_ratio(): void
    {
        $svg = $this->build(['pixel_width' => 900, 'pixel_height' => 421, 'width' => 5.5, 'unit' => 'in']);
        $this->assertStringContainsString('width="5.5in" height="2.5728in"', $svg);
        (new SvgValidationService)->validate($svg);
    }

    public function test_redimensioning_changes_only_root_width_and_height(): void
    {
        $export = new SvgExportService;
        $svg = $this->build();
        $resized = $export->withDimensions($svg, 6, 'in');
        $this->assertStringContainsString('width="6in" height="4in" viewBox="0 0 1500 1000"', $resized);
        $strip = fn (string $s) => preg_replace('/ (width|height)="[^"]*"/', '', $s, 2);
        $this->assertSame($strip($svg), $strip($resized), 'geometry and viewBox are byte-identical');
        (new SvgValidationService)->validate($resized);
    }

    public function test_outline_mode_uses_stroke_and_never_adds_a_background_rectangle(): void
    {
        $svg = $this->build(['outline' => true, 'white_background' => true]);
        $this->assertStringContainsString('fill="none" stroke="#000000"', $svg);
        $this->assertStringNotContainsString('<rect', $svg);
        $this->assertStringContainsString('<rect', $this->build(['white_background' => true]));
    }

    public function test_export_contains_no_raster_metadata_or_transforms(): void
    {
        $svg = $this->build();
        foreach (['<image', 'xlink', 'href', '<metadata', 'transform', '<script', '<style', 'base64', '<!DOCTYPE'] as $needle) {
            $this->assertStringNotContainsString($needle, $svg);
        }
    }

    public function test_validator_rejects_unsafe_or_malformed_documents(): void
    {
        $good = $this->build();
        $bad = [
            'script' => str_replace('</svg>', '<script>alert(1)</script></svg>', $good),
            'image' => str_replace('</svg>', '<image href="data:image/png;base64,AAAA"/></svg>', $good),
            'event handler' => str_replace('<path ', '<path onload="alert(1)" ', $good),
            'foreignObject' => str_replace('</svg>', '<foreignObject/></svg>', $good),
            'doctype entity' => str_replace('<svg ', '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg ', $good),
            'style attribute' => str_replace('<path ', '<path style="fill:url(http://x)" ', $good),
            'wrong aspect' => str_replace('height="100mm"', 'height="90mm"', $good),
            'no unit' => str_replace('width="150mm"', 'width="150"', $good),
            'no paths' => preg_replace('/<path[^>]*>/', '', $good),
            'not xml' => '<svg',
            'arc command' => preg_replace('/d="[^"]*"/', 'd="M0 0 A5 5 0 0 1 9 9"', $good),
        ];
        foreach ($bad as $label => $svg) {
            try {
                (new SvgValidationService)->validate($svg);
                $this->fail("Validator accepted: {$label}");
            } catch (PicToSvgException $e) {
                $this->assertSame('invalid_svg', $e->errorCode, $label);
            }
        }
    }
}
