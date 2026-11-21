<?php

namespace Tests\Unit;

use App\Services\Svg\PathGeometry;
use App\Services\Svg\SvgOptimizationService;
use PHPUnit\Framework\TestCase;

/** OPT-001: simplification must reduce nodes without harming corners, curves or holes. */
class SvgOptimizationServiceTest extends TestCase
{
    private function opt(array $shapes, float $tolerance = 0.5, float $minArea = 0.0): array
    {
        return (new SvgOptimizationService)->optimize($shapes, ['tolerance' => $tolerance, 'min_area' => $minArea]);
    }

    public function test_removes_nodes_on_straight_runs_but_keeps_corners(): void
    {
        // square 100x100 with 3 redundant, slightly wobbly nodes on each edge
        $d = 'M0 0 L25 0.2 L50 -0.2 L75 0.1 L100 0 L100.1 25 L99.9 50 L100 75 L100 100 L75 100.2 L50 99.9 L25 100 L0 100 L0.1 75 L-0.1 50 L0 25 Z';
        $out = $this->opt([PathGeometry::parse($d)])['shapes'];
        $sp = $out[0][0];
        $this->assertSame(4, PathGeometry::nodeCount($sp), 'only the four corners remain');
        $this->assertEqualsWithDelta(10000.0, abs(PathGeometry::signedArea($sp)), 1.0, 'area is unchanged');
        $ends = array_map(fn ($s) => [$s[1], $s[2]], $sp['segments']);
        foreach ([[100.0, 0.0], [100.0, 100.0], [0.0, 100.0]] as $corner) {
            $this->assertContains($corner, $ends);
        }
    }

    public function test_does_not_cut_a_real_corner_even_with_generous_tolerance(): void
    {
        $out = $this->opt([PathGeometry::parse('M0 0 L50 0 L50 3 L100 3 L100 50 L0 50 Z')], 1.0)['shapes'];
        $this->assertSame(6, PathGeometry::nodeCount($out[0][0]), 'a 3-unit step is geometry, not noise');
    }

    public function test_real_curves_stay_curves_and_flat_curves_become_lines(): void
    {
        $out = $this->opt([PathGeometry::parse('M0 0 C30 0.01 60 -0.01 100 0 C100 55 55 100 0 100 Z')])['shapes'];
        $types = array_column($out[0][0]['segments'], 0);
        $this->assertSame(['L', 'C'], $types);
    }

    public function test_removes_tiny_specks_and_tiny_holes_but_preserves_real_holes(): void
    {
        $ring = PathGeometry::parse('M0 0 l100 0 0 100 -100 0 z M30 30 l0 40 40 0 0 -40 z M5 5 l0 1 1 0 0 -1 z');
        $speck = PathGeometry::parse('M200 200 l2 0 0 2 -2 0 z');
        $result = $this->opt([$ring, $speck], 0.5, 10.0);
        $this->assertCount(1, $result['shapes'], 'the isolated speck shape is gone');
        $this->assertCount(2, $result['shapes'][0], 'outer contour + the real hole remain; the 1px hole is gone');
        $this->assertSame(2, $result['removed_specks']);
        // winding of outer and hole is untouched (opposite signs)
        $this->assertLessThan(0, PathGeometry::signedArea($result['shapes'][0][0]) * PathGeometry::signedArea($result['shapes'][0][1]));
    }

    public function test_removes_exact_duplicate_contours_only(): void
    {
        $a = PathGeometry::parse('M0 0 l10 0 0 10 -10 0 z');
        $b = PathGeometry::parse('M0 0 l10 0 0 10 -10 0 z');
        $c = PathGeometry::parse('M0 0 l10 0 0 11 -10 0 z');
        $result = $this->opt([$a, $b, $c]);
        $this->assertCount(2, $result['shapes']);
        $this->assertSame(1, $result['removed_duplicates']);
    }

    public function test_long_straight_runs_are_simplified_in_bounded_time(): void
    {
        $d = 'M0 0';
        for ($i = 1; $i <= 20000; $i++) {
            $d .= ' L'.$i.' '.(($i % 2) * 0.1);
        }
        $d .= ' L20000 50 L0 50 Z';
        $start = microtime(true);
        $out = $this->opt([PathGeometry::parse($d)], 1.0)['shapes'];
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertLessThan(500, PathGeometry::nodeCount($out[0][0]));
        $this->assertEqualsWithDelta(1_000_000, abs(PathGeometry::signedArea($out[0][0])), 2000);
    }

    public function test_zero_tolerance_changes_nothing(): void
    {
        $shape = PathGeometry::parse('M0 0 L50 0 L100 0 L100 100 Z');
        $this->assertSame([$shape], $this->opt([$shape], 0.0)['shapes']);
    }
}
