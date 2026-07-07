<?php

namespace App\Game\Geo;

use App\Game\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * Live RegionSource over the Overpass API (via the resilient OverpassHttp layer). Uses `is_in`
 * over the generated area DB for containment, and boundary-way geometry for distance-to-border.
 * Results are cached by coarse location with a longer-lived stale copy for outages — same policy
 * as OverpassMapDataSource, so a question never blocks the game.
 */
final class OverpassRegionSource implements RegionSource
{
    private const TIMEOUT = 10;
    private const STALE_TTL_DAYS = 7;
    /** Grow the boundary search only if the smaller (cheaper) radius finds nothing. */
    private const BOUNDARY_RADII_M = [20000, 60000, 200000];

    public function __construct(private readonly OverpassHttp $http) {}

    public function areaContaining(float $lat, float $lng, int $adminLevel): ?GeoArea
    {
        $ql = "[out:json][timeout:25];is_in({$lat},{$lng});"
            ."area._[\"boundary\"=\"administrative\"][\"admin_level\"=\"{$adminLevel}\"];out tags;";
        $elements = $this->cachedFetch(sprintf('overpass:area:%d:%.3f:%.3f', $adminLevel, $lat, $lng), $ql);

        foreach ($elements ?? [] as $el) {
            if (($el['type'] ?? null) === 'area') {
                return new GeoArea((string) ($el['id'] ?? ''), $adminLevel, $el['tags']['name'] ?? null);
            }
        }

        return null;
    }

    public function nearestBoundary(float $lat, float $lng, int $adminLevel): ?GeoBoundaryHit
    {
        foreach (self::BOUNDARY_RADII_M as $radius) {
            $ql = "[out:json][timeout:30];"
                ."way[\"boundary\"=\"administrative\"][\"admin_level\"=\"{$adminLevel}\"](around:{$radius},{$lat},{$lng});out geom;";
            $elements = $this->cachedFetch(sprintf('overpass:boundary:%d:%d:%.2f:%.2f', $adminLevel, $radius, $lat, $lng), $ql);
            if ($elements === null) {
                return null; // outage (stale already tried) — let the question fall back to manual
            }

            $hit = $this->nearestFromWays($lat, $lng, $elements);
            if ($hit !== null) {
                return $hit;
            }
            // Nothing within this radius — widen and try again.
        }

        return null;
    }

    /** @param  array<int, array<string, mixed>>  $ways */
    private function nearestFromWays(float $lat, float $lng, array $ways): ?GeoBoundaryHit
    {
        $best = null;
        foreach ($ways as $way) {
            $path = [];
            foreach ($way['geometry'] ?? [] as $pt) {
                if (isset($pt['lat'], $pt['lon'])) {
                    $path[] = [(float) $pt['lat'], (float) $pt['lon']];
                }
            }
            $near = Geo::nearestOnPath($lat, $lng, $path);
            if ($near !== null && ($best === null || $near['distance'] < $best['distance'])) {
                $best = $near;
            }
        }

        return $best === null ? null : new GeoBoundaryHit($best['distance'], $best['lat'], $best['lng']);
    }

    /**
     * @return array<int, array<string, mixed>>|null  elements on success (maybe empty), null on a
     *                                                 total outage with no stale copy
     */
    private function cachedFetch(string $cacheKey, string $ql): ?array
    {
        $staleKey = "stale:{$cacheKey}";

        $elements = Cache::get($cacheKey);
        if ($elements === null) {
            $result = $this->http->fetch($ql, self::TIMEOUT, maxAttempts: 1);
            $elements = $result === null ? null : ($result['elements'] ?? []);
            if ($elements !== null) {
                Cache::put($cacheKey, $elements, now()->addHours(6));
                Cache::put($staleKey, $elements, now()->addDays(self::STALE_TTL_DAYS));
            } else {
                $elements = Cache::get($staleKey); // last-known-good, or null
            }
        }

        return $elements;
    }
}
