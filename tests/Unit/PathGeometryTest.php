<?php

namespace Tests\Unit;

use App\Services\Svg\PathGeometry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** EXPORT-002 / TRACE-001: path data parsing, transform baking and serialisation. */
class PathGeometryTest extends TestCase
{
    public function test_parses_relative_commands_with_implicit_repetition_and_subpaths(): void
    {
        // potrace style: absolute M, relative l/c with repeated arguments, relative m after z
        $sp = PathGeometry::parse('M10 10 l10 0 0 10 -10 0 z m2 2 c1 0 2 1 2 2 l-2 0 z');
        $this->assertCount(2, $sp);
        $this->assertSame([10.0, 10.0], $sp[0]['start']);
        $this->assertSame([['L', 20.0, 10.0], ['L', 20.0, 20.0], ['L', 10.0, 20.0]], $sp[0]['segments']);
        $this->assertTrue($sp[0]['closed']);
        // "m" after "z" is relative to the start of the closed subpath
        $this->assertSame([12.0, 12.0], $sp[1]['start']);
        $this->assertSame(['C', 13.0, 12.0, 14.0, 13.0, 14.0, 14.0], $sp[1]['segments'][0]);
        $this->assertSame(['L', 12.0, 14.0], $sp[1]['segments'][1]);
    }

    public function test_implicit_lineto_after_moveto_and_negative_number_separators(): void
    {
        $sp = PathGeometry::parse('M0 0 5 5-5 5z');
        $this->assertSame([['L', 5.0, 5.0], ['L', -5.0, 5.0]], $sp[0]['segments']);
    }

    public function test_rejects_unsupported_commands_and_bad_argument_counts(): void
    {
        foreach (['M0 0 A5 5 0 0 1 10 10', 'M0 0 l5', 'M0 0 h5', '5 5 l1 1'] as $bad) {
            try {
                PathGeometry::parse($bad);
                $this->fail("Expected rejection of: {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_bakes_potrace_flip_transform_into_coordinates(): void
    {
        // potrace: translate(0,200) scale(0.1,-0.1)  →  x' = 0.1x, y' = 200 - 0.1y
        $sp = PathGeometry::transform(PathGeometry::parse('M100 1900 l100 0 0 -100 z'), 0.1, -0.1, 0.0, 200.0);
        $this->assertEqualsWithDelta([10.0, 10.0], $sp[0]['start'], 1e-9);
        $this->assertEqualsWithDelta(['L', 20.0, 10.0], $sp[0]['segments'][0], 1e-9);
        $this->assertEqualsWithDelta(['L', 20.0, 20.0], $sp[0]['segments'][1], 1e-9);
    }

    public function test_serialise_round_trips_without_accumulating_rounding_error(): void
    {
        $segments = [];
        $x = 0.0;
        for ($i = 0; $i < 500; $i++) {
            $x += 0.14; // each delta rounds to 0.1 — naive relative output would drift by 20 units
            $segments[] = ['L', $x, 0.0];
        }
        $d = PathGeometry::serialize([['start' => [0.0, 0.0], 'segments' => $segments, 'closed' => true]], 1);
        $back = PathGeometry::parse($d);
        $last = end($back[0]['segments']);
        $this->assertEqualsWithDelta(70.0, $last[1], 0.051);
        $this->assertStringEndsWith('z', $d);
    }

    public function test_node_count_and_signed_area(): void
    {
        $square = PathGeometry::parse('M0 0 l10 0 0 10 -10 0 z')[0];
        $this->assertSame(4, PathGeometry::nodeCount($square));
        $this->assertEqualsWithDelta(100.0, abs(PathGeometry::signedArea($square)), 1e-9);
        $explicitlyClosed = PathGeometry::parse('M0 0 l10 0 0 10 -10 0 0 -10 z')[0];
        $this->assertSame(4, PathGeometry::nodeCount($explicitlyClosed));
    }
}
