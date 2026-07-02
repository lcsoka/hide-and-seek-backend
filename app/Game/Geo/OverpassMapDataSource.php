<?php

namespace App\Game\Geo;

use App\Game\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live backend over the public OpenStreetMap Overpass API. Results are cached by
 * coarse location; any failure returns empty/null so the question falls back to a
 * manual hider answer (never blocks the game). A local PostGIS backend can later
 * replace this behind the same interface.
 */
final class OverpassMapDataSource implements MapDataSource
{
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

        // Only successful responses are cached; a failed fetch returns [] and retries
        // next time instead of poisoning the cache with an empty result for hours.
        $elements = Cache::get($cacheKey);
        if ($elements === null) {
            $elements = $this->fetch($key, $value, $radius, $lat, $lng);
            if ($elements !== null) {
                Cache::put($cacheKey, $elements, now()->addHours(6));
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
     * Query Overpass across the configured endpoints. Returns the elements on the
     * first success, or null if every endpoint failed (so the caller won't cache it).
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

        $endpoints = (array) config('game.overpass.endpoints', [config('game.overpass.endpoint')]);
        $userAgent = (string) config('game.overpass.user_agent', 'Bujocska/1.0');

        foreach ($endpoints as $endpoint) {
            try {
                // Bounded so a slow/throttled mirror can never exhaust PHP's request time
                // limit (two endpoints × 12 s < 30 s); a failure falls back to a manual answer.
                $response = Http::withHeaders(['User-Agent' => $userAgent])
                    ->asForm()->connectTimeout(5)->timeout(12)->post($endpoint, ['data' => $ql]);

                if ($response->successful()) {
                    return $response->json('elements') ?? [];
                }
            } catch (Throwable) {
                // try the next endpoint
            }
        }

        return null;
    }
}
