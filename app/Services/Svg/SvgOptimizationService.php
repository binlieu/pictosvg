<?php

namespace App\Services\Svg;

/**
 * Geometry clean-up that runs AFTER tracing and is kept separate from it, so the raw
 * trace can always be compared with the optimised result.
 *
 * It never merges shapes, never reorders subpaths inside a shape and never changes
 * winding, so holes and fill behaviour are preserved.
 */
class SvgOptimizationService
{
    /**
     * @param  list<list<array>>  $shapes  each shape = list of subpaths (outer contour first)
     * @param  array{tolerance: float, min_area: float}  $options  in viewBox units
     * @return array{shapes: list<list<array>>, removed_specks: int, removed_duplicates: int}
     */
    public function optimize(array $shapes, array $options): array
    {
        $tolerance = max(0.0, (float) ($options['tolerance'] ?? 0.0));
        $minArea = max(0.0, (float) ($options['min_area'] ?? 0.0));
        $removedSpecks = 0;
        $removedDuplicates = 0;
        $seen = [];
        $out = [];

        foreach ($shapes as $shape) {
            $kept = [];
            foreach ($shape as $index => $subpath) {
                // Tiny artifacts: a tiny outer contour removes the whole shape (its holes are
                // necessarily smaller); a tiny hole is dropped on its own.
                if ($minArea > 0 && abs(PathGeometry::signedArea($subpath)) < $minArea) {
                    $removedSpecks++;
                    if ($index === 0) {
                        $removedSpecks += count($shape) - 1;
                        $kept = [];
                        break;
                    }

                    continue;
                }
                if ($tolerance > 0) {
                    $subpath = $this->straightenFlatCurves($subpath, $tolerance);
                    $subpath = $this->mergeCollinearLines($subpath, $tolerance);
                }
                // Exact duplicate geometry (same contour traced twice) would double-cut.
                $key = md5(PathGeometry::serialize([$subpath], 1));
                if (isset($seen[$key])) {
                    $removedDuplicates++;
                    if ($index === 0) {
                        $kept = [];
                        break;
                    }

                    continue;
                }
                $seen[$key] = true;
                $kept[] = $subpath;
            }
            if ($kept !== []) {
                $out[] = $kept;
            }
        }

        return ['shapes' => $out, 'removed_specks' => $removedSpecks, 'removed_duplicates' => $removedDuplicates];
    }

    /** A cubic whose control points sit on its chord is a straight line: store it as one. */
    private function straightenFlatCurves(array $subpath, float $tolerance): array
    {
        [$x, $y] = $subpath['start'];
        $flat = $tolerance * 0.25; // much stricter than line merging: curves must stay curves
        foreach ($subpath['segments'] as &$seg) {
            if ($seg[0] === 'C') {
                $ex = $seg[5];
                $ey = $seg[6];
                if ($this->between([$x, $y], [$ex, $ey], [$seg[1], $seg[2]], $flat)
                    && $this->between([$x, $y], [$ex, $ey], [$seg[3], $seg[4]], $flat)) {
                    $seg = ['L', $ex, $ey];
                }
                [$x, $y] = [$ex, $ey];
            } else {
                [$x, $y] = [$seg[1], $seg[2]];
            }
        }
        unset($seg);

        return $subpath;
    }

    /**
     * Removes nodes that lie on a straight run of line segments. A node is only dropped when
     * every node skipped so far stays within tolerance of the new single segment, so sharp
     * corners (which are far from the chord) are always kept.
     */
    private function mergeCollinearLines(array $subpath, float $tolerance): array
    {
        $segments = $subpath['segments'];
        // Make the closing edge explicit so nodes lying on it can be merged too.
        $last = end($segments);
        if ($subpath['closed'] && $last !== false && $last[0] === 'L'
            && ($last[1] !== $subpath['start'][0] || $last[2] !== $subpath['start'][1])) {
            $segments[] = ['L', $subpath['start'][0], $subpath['start'][1]];
        }
        $result = [];
        $anchor = $subpath['start'];
        $run = []; // consecutive 'L' end points since $anchor

        $flushRun = function () use (&$run, &$result, &$anchor, $tolerance): void {
            $count = count($run);
            $i = 0;
            $from = $anchor;
            while ($i < $count) {
                // extend as far as all intermediate points stay on the chord
                $j = $i;
                while ($j + 1 < $count) {
                    $ok = true;
                    for ($k = $i; $k <= $j; $k++) {
                        if (! $this->between($from, $run[$j + 1], $run[$k], $tolerance)) {
                            $ok = false;
                            break;
                        }
                    }
                    if (! $ok) {
                        break;
                    }
                    $j++;
                }
                $result[] = ['L', $run[$j][0], $run[$j][1]];
                $from = $run[$j];
                $i = $j + 1;
            }
            if ($count > 0) {
                $anchor = $run[$count - 1];
            }
            $run = [];
        };

        foreach ($segments as $seg) {
            if ($seg[0] === 'L') {
                $run[] = [$seg[1], $seg[2]];

                continue;
            }
            $flushRun();
            $result[] = $seg;
            $anchor = [$seg[5], $seg[6]];
        }
        $flushRun();

        $subpath['segments'] = $result;

        return $subpath;
    }

    /** True when $p lies within $tolerance of segment a→b and projects inside it. */
    private function between(array $a, array $b, array $p, float $tolerance): bool
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 < 1e-12) {
            return hypot($p[0] - $a[0], $p[1] - $a[1]) <= $tolerance;
        }
        $t = (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len2;
        if ($t < 0 || $t > 1) {
            return false;
        }
        $dist = abs(($p[0] - $a[0]) * $dy - ($p[1] - $a[1]) * $dx) / sqrt($len2);

        return $dist <= $tolerance;
    }
}
