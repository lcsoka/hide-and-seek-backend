<?php

namespace App\Game\Support;

final class Geo
{
    /** Great-circle distance between two points, in metres (Haversine). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6_371_000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * The nearest point on a polyline to a query point, and the distance to it (metres). Projects
     * to a local equirectangular plane around the query point (accurate at admin-boundary scale)
     * and finds the closest point on each segment. Returns null for an empty path.
     *
     * @param  array<int, array{0: float, 1: float}>  $path  vertices as [lat, lng]
     * @return array{lat: float, lng: float, distance: float}|null
     */
    public static function nearestOnPath(float $lat, float $lng, array $path): ?array
    {
        $n = count($path);
        if ($n === 0) {
            return null;
        }

        // Metres per degree at the query latitude — the local plane's scale.
        $mPerDegLat = 110540.0;
        $mPerDegLng = 111320.0 * cos(deg2rad($lat));
        $toXY = fn (array $p): array => [($p[1] - $lng) * $mPerDegLng, ($p[0] - $lat) * $mPerDegLat];

        $bestX = null;
        $bestY = null;
        $bestD2 = INF;
        $consider = function (float $x, float $y) use (&$bestX, &$bestY, &$bestD2): void {
            $d2 = $x * $x + $y * $y;
            if ($d2 < $bestD2) {
                $bestD2 = $d2;
                $bestX = $x;
                $bestY = $y;
            }
        };

        if ($n === 1) {
            [$x, $y] = $toXY($path[0]);
            $consider($x, $y);
        }
        for ($i = 0; $i < $n - 1; $i++) {
            [$ax, $ay] = $toXY($path[$i]);
            [$bx, $by] = $toXY($path[$i + 1]);
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0.0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $consider($ax + $t * $dx, $ay + $t * $dy);
        }

        if ($bestX === null) {
            return null;
        }
        $nearLat = $lat + $bestY / $mPerDegLat;
        $nearLng = $lng + ($mPerDegLng != 0.0 ? $bestX / $mPerDegLng : 0.0);

        return ['lat' => $nearLat, 'lng' => $nearLng, 'distance' => self::distanceMeters($lat, $lng, $nearLat, $nearLng)];
    }
}
