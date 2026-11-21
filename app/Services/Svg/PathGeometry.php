<?php

namespace App\Services\Svg;

use InvalidArgumentException;

/**
 * Minimal SVG path-data model for traced output.
 *
 * A "shape" is one <path>: a list of subpaths (first = outer contour, rest = its holes).
 * A subpath is ['start' => [x, y], 'segments' => [...], 'closed' => bool] where a segment is
 * ['L', x, y] or ['C', x1, y1, x2, y2, x, y] in absolute coordinates.
 */
final class PathGeometry
{
    /**
     * Parses path data that uses M/m, L/l, C/c, Z/z (the commands tracers emit) into
     * absolute subpaths.
     *
     * @return list<array{start: array{0: float, 1: float}, segments: list<array>, closed: bool}>
     */
    public static function parse(string $d): array
    {
        if (preg_match_all('/([MmLlCcZz])|(-?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)/', $d, $m, PREG_SET_ORDER) === false) {
            throw new InvalidArgumentException('Unreadable path data.');
        }
        if (preg_match('/[^MmLlCcZz0-9eE.,\s+-]/', $d) === 1) {
            throw new InvalidArgumentException('Unsupported path command.');
        }

        $subpaths = [];
        $current = null;
        $cmd = null;
        $x = 0.0;
        $y = 0.0;
        $sx = 0.0;
        $sy = 0.0;
        $nums = [];

        $flush = function () use (&$subpaths, &$current): void {
            if ($current !== null) {
                $subpaths[] = $current;
                $current = null;
            }
        };

        $consume = function () use (&$nums, &$cmd, &$x, &$y, &$sx, &$sy, &$current, $flush): void {
            while (true) {
                $need = match ($cmd) {
                    'M', 'm', 'L', 'l' => 2,
                    'C', 'c' => 6,
                    default => 0,
                };
                if ($need === 0 || count($nums) < $need) {
                    return;
                }
                $args = array_splice($nums, 0, $need);
                $rel = ctype_lower($cmd);
                $ox = $rel ? $x : 0.0;
                $oy = $rel ? $y : 0.0;
                switch ($cmd) {
                    case 'M':
                    case 'm':
                        $flush();
                        $x = $ox + $args[0];
                        $y = $oy + $args[1];
                        $sx = $x;
                        $sy = $y;
                        $current = ['start' => [$x, $y], 'segments' => [], 'closed' => false];
                        $cmd = $rel ? 'l' : 'L'; // implicit lineto after moveto
                        break;
                    case 'L':
                    case 'l':
                        if ($current === null) {
                            $current = ['start' => [$x, $y], 'segments' => [], 'closed' => false];
                        }
                        $x = $ox + $args[0];
                        $y = $oy + $args[1];
                        $current['segments'][] = ['L', $x, $y];
                        break;
                    default:
                        if ($current === null) {
                            $current = ['start' => [$x, $y], 'segments' => [], 'closed' => false];
                        }
                        $seg = ['C', $ox + $args[0], $oy + $args[1], $ox + $args[2], $oy + $args[3], $ox + $args[4], $oy + $args[5]];
                        $x = $seg[5];
                        $y = $seg[6];
                        $current['segments'][] = $seg;
                }
            }
        };

        foreach ($m as $tok) {
            if ($tok[1] !== '') {
                if ($nums !== []) {
                    throw new InvalidArgumentException('Path data has the wrong number of coordinates.');
                }
                $cmd = $tok[1];
                if ($cmd === 'Z' || $cmd === 'z') {
                    if ($current !== null) {
                        $current['closed'] = true;
                        $flush();
                    }
                    $x = $sx;
                    $y = $sy;
                }
            } else {
                if ($cmd === null || $cmd === 'Z' || $cmd === 'z') {
                    throw new InvalidArgumentException('Path data has coordinates without a command.');
                }
                $nums[] = (float) $tok[2];
                $consume();
            }
        }
        if ($nums !== []) {
            throw new InvalidArgumentException('Path data has the wrong number of coordinates.');
        }
        $flush();

        return array_values(array_filter($subpaths, fn ($s) => $s['segments'] !== []));
    }

    /** Applies x' = a*x + e, y' = d*y + f to every coordinate. */
    public static function transform(array $subpaths, float $a, float $d, float $e, float $f): array
    {
        foreach ($subpaths as &$sp) {
            $sp['start'] = [$a * $sp['start'][0] + $e, $d * $sp['start'][1] + $f];
            foreach ($sp['segments'] as &$seg) {
                for ($i = 1, $n = count($seg); $i < $n; $i += 2) {
                    $seg[$i] = $a * $seg[$i] + $e;
                    $seg[$i + 1] = $d * $seg[$i + 1] + $f;
                }
            }
            unset($seg);
        }

        return $subpaths;
    }

    /** Anchor points (nodes) of a subpath, excluding a final point that coincides with the start. */
    public static function nodeCount(array $subpath): int
    {
        $n = 1 + count($subpath['segments']);
        $last = end($subpath['segments']);
        if ($subpath['closed'] && $last !== false) {
            $k = count($last);
            if (abs($last[$k - 2] - $subpath['start'][0]) < 1e-9 && abs($last[$k - 1] - $subpath['start'][1]) < 1e-9) {
                $n--;
            }
        }

        return $n;
    }

    /** Polyline approximation (curves sampled) used for area/bounds. */
    public static function flatten(array $subpath, int $steps = 6): array
    {
        $pts = [$subpath['start']];
        [$x, $y] = $subpath['start'];
        foreach ($subpath['segments'] as $seg) {
            if ($seg[0] === 'L') {
                $pts[] = [$seg[1], $seg[2]];
                [$x, $y] = [$seg[1], $seg[2]];

                continue;
            }
            for ($i = 1; $i <= $steps; $i++) {
                $t = $i / $steps;
                $u = 1 - $t;
                $pts[] = [
                    $u ** 3 * $x + 3 * $u * $u * $t * $seg[1] + 3 * $u * $t * $t * $seg[3] + $t ** 3 * $seg[5],
                    $u ** 3 * $y + 3 * $u * $u * $t * $seg[2] + 3 * $u * $t * $t * $seg[4] + $t ** 3 * $seg[6],
                ];
            }
            [$x, $y] = [$seg[5], $seg[6]];
        }

        return $pts;
    }

    /** Signed area (shoelace) of the flattened subpath. */
    public static function signedArea(array $subpath): float
    {
        $pts = self::flatten($subpath);
        $n = count($pts);
        $a = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $a += $pts[$i][0] * $pts[$j][1] - $pts[$j][0] * $pts[$i][1];
        }

        return $a / 2;
    }

    /**
     * Serialises subpaths as compact relative path data. Relative deltas are derived from
     * the rounded absolute positions, so rounding error never accumulates.
     */
    public static function serialize(array $subpaths, int $precision): string
    {
        $r = fn (float $v): float => round($v, $precision);
        $fmt = function (float $v) use ($precision): string {
            $s = number_format($v, $precision, '.', '');
            if (str_contains($s, '.')) {
                $s = rtrim(rtrim($s, '0'), '.');
            }
            if ($s === '-0' || $s === '') {
                $s = '0';
            }

            return $s;
        };
        $join = function (array $vals) use ($fmt): string {
            $out = '';
            foreach ($vals as $i => $v) {
                $s = $fmt($v);
                $out .= ($i > 0 && $s[0] !== '-') ? ' '.$s : $s;
            }

            return $out;
        };

        $d = '';
        foreach ($subpaths as $sp) {
            $cx = $r($sp['start'][0]);
            $cy = $r($sp['start'][1]);
            $d .= 'M'.$join([$cx, $cy]);
            $prev = '';
            foreach ($sp['segments'] as $seg) {
                if ($seg[0] === 'L') {
                    $nx = $r($seg[1]);
                    $ny = $r($seg[2]);
                    $args = [$nx - $cx, $ny - $cy];
                    $letter = 'l';
                } else {
                    $nx = $r($seg[5]);
                    $ny = $r($seg[6]);
                    $args = [$r($seg[1]) - $cx, $r($seg[2]) - $cy, $r($seg[3]) - $cx, $r($seg[4]) - $cy, $nx - $cx, $ny - $cy];
                    $letter = 'c';
                }
                $body = $join($args);
                if ($letter === $prev) {
                    $d .= ($body[0] === '-' ? '' : ' ').$body;
                } else {
                    $d .= $letter.$body;
                }
                $prev = $letter;
                $cx = $nx;
                $cy = $ny;
            }
            if ($sp['closed']) {
                $d .= 'z';
            }
        }

        return $d;
    }
}
