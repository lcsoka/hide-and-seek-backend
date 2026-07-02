<?php

namespace App\Game\Geo;

use App\Game\Support\Geo;
use Illuminate\Support\Facades\Cache;

/**
 * Live backend over the public OpenStreetMap Overpass API (via the resilient OverpassHttp
 * layer). Results are cached by coarse location; on a total failure the last-known-good
 * result is served (stale fallback), and only if there's none does the question fall back to
 * a manual hider answer — it never blocks the game. A local PostGIS backend can later replace
 * this behind the same interface.
 */
final class OverpassMapDataSource implements MapDataSource
{
    private const TIMEOUT = 10;
    private const STALE_TTL_DAYS = 7;

    public function __construct(private readonly OverpassHttp $http) {}

    public function nearest(string $type, float $lat, float $lng): ?GeoFeature
    {
        $radius = (float) config('game.overpass.search_radius_m', 50000);
        $nearest = null;
        $nearestDistance = INF;

        foreach ($this->within($type, $lat, $lng, $radius) as $feature) {
            $distance = Geo::distanceMeters($lat, $lng, $feature->lat, $feature->lng);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $feature;
            }
        }

        return $nearest;
    }

    public function within(string $type, float $lat, float $lng, float $radiusM): array
    {
        $tag = config("game.overpass.features.{$type}");
        if (! is_string($tag) || ! str_contains($tag, '=')) {
            return [];
        }

        [$key, $value] = explode('=', $tag, 2);
        $radius = (int) $radiusM;
        $cacheKey = sprintf('overpass:%s:%d:%.3f:%.3f', $type, $radius, $lat, $lng);
        $staleKey = "stale:{$cacheKey}";

        $elements = Cache::get($cacheKey);
        if ($elements === null) {
            $elements = $this->fetch($key, $value, $radius, $lat, $lng);
            if ($elements !== null) {
                // Cache the success (fresh 6h + a longer-lived stale copy for outages).
                Cache::put($cacheKey, $elements, now()->addHours(6));
                Cache::put($staleKey, $elements, now()->addDays(self::STALE_TTL_DAYS));
            } else {
                // Overpass unreachable — use the last-known-good result if we have one.
                $elements = Cache::get($staleKey);
            }
        }
        $elements ??= [];

        $features = [];
        foreach ($elements as $element) {
            $featureLat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $featureLng = $element['lon'] ?? ($element['center']['lon'] ?? null);
            if ($featureLat === null || $featureLng === null) {
                continue;
            }

            $features[] = new GeoFeature(
                id: ($element['type'] ?? 'node').'/'.($element['id'] ?? ''),
                type: $type,
                lat: (float) $featureLat,
                lng: (float) $featureLng,
                name: $element['tags']['name'] ?? null,
            );
        }

        return $features;
    }

    /**
     * Query Overpass (via the resilient layer). Returns the elements on success (possibly an
     * empty array — a valid "no features here"), or null if every mirror failed so the caller
     * can distinguish a genuine empty result from an outage and fall back to stale/manual.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetch(string $key, string $value, int $radius, float $lat, float $lng): ?array
    {
        $ql = '[out:json][timeout:15];('
            ."node[\"{$key}\"=\"{$value}\"](around:{$radius},{$lat},{$lng});"
            ."way[\"{$key}\"=\"{$value}\"](around:{$radius},{$lat},{$lng});"
            ."relation[\"{$key}\"=\"{$value}\"](around:{$radius},{$lat},{$lng});"
            .');out center;';

        // One attempt per mirror (no per-endpoint retry): question truth runs in ComputeQuestionTruth
        // which already retries with backoff at the job level, and the synchronous callers (hiding-zone
        // confirm, inline answer fallback) must not block on retries and risk a gateway timeout.
        $result = $this->http->fetch($ql, self::TIMEOUT, maxAttempts: 1);

        return $result === null ? null : ($result['elements'] ?? []);
    }
}
