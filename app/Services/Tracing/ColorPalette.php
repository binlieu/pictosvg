<?php

namespace App\Services\Tracing;

/**
 * Chooses the solid colours of a picture from a colour histogram.
 *
 * Anti-aliased edges and JPEG noise create many in-between shades. Those are merged into
 * the real colours; small but genuinely different colours (a small blue dot) are kept.
 */
final class ColorPalette
{
    /**
     * @param  array<string, int>  $histogram  #rrggbb => pixel count
     * @return list<string> chosen colours, most common first (exact colours from the picture)
     */
    public static function choose(array $histogram, int $max): array
    {
        arsort($histogram);
        $total = max(1, array_sum($histogram));
        $kept = [];
        foreach ($histogram as $hex => $count) {
            $rgb = self::rgb($hex);
            $share = $count / $total;
            if ($kept === []) {
                $kept[$hex] = $rgb;

                continue;
            }
            $nearest = min(array_map(fn ($k) => self::distance($rgb, $k), $kept));
            if ($nearest < 40                                        // a shade of a colour we already have
                || $share < 0.0005                                   // too small to be artwork
                || ($share < 0.02 && self::isBlend($rgb, $kept))     // an edge blend of two real colours
                || count($kept) >= $max) {
                continue;
            }
            $kept[$hex] = $rgb;
        }

        return array_keys($kept);
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** True when $c lies close to the straight line between two kept colours (a mix of them). */
    private static function isBlend(array $c, array $kept): bool
    {
        $list = array_values($kept);
        for ($i = 0, $n = count($list); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                [$a, $b] = [$list[$i], $list[$j]];
                $ab = [$b[0] - $a[0], $b[1] - $a[1], $b[2] - $a[2]];
                $len2 = $ab[0] ** 2 + $ab[1] ** 2 + $ab[2] ** 2;
                if ($len2 < 1) {
                    continue;
                }
                $t = (($c[0] - $a[0]) * $ab[0] + ($c[1] - $a[1]) * $ab[1] + ($c[2] - $a[2]) * $ab[2]) / $len2;
                if ($t <= 0 || $t >= 1) {
                    continue;
                }
                $p = [$a[0] + $t * $ab[0], $a[1] + $t * $ab[1], $a[2] + $t * $ab[2]];
                if (self::distance($c, $p) < 30) {
                    return true;
                }
            }
        }

        return false;
    }
}
