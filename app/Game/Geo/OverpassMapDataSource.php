<?php

namespace App\Game\Geo;

use App\Game\Support\Geo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live backend over the public OpenStreetMap Overpass API. Results are cached by
 * coarse location; any failure returns null so the question falls back to a
 * manual hider answer (never blocks the game). A local PostGIS backend can later
 * replace this behind the same interface.
 */
final class OverpassMapDataSource implements MapDataSource
{
    public function nearest(string $type, float $lat, float $lng): ?GeoFeature
    {
        $tag = config("game.overpass.features.{$type}");
        if (! is_string($tag) || ! str_contains($tag, '=')) {
            return null;
        }

        [$key, $value] = explode('=', $tag, 2);
        $radius = (int) config('game.overpass.search_radius_m', 50000);
        $endpoint = (string) config('game.overpass.endpoint');
        $cacheKey = sprintf('overpass:%s:%.3f:%.3f', $type, $lat, $lng);

        try {
            $elements = Cache::remember($cacheKey, now()->addHours(6), function () use ($key, $value, $radius, $lat, $lng, $endpoint) {
                $ql = '[out:json][timeout:25];('
                    ."node[\"{$key}\"=\"{$value}\"](around:{$radius},{$lat},{$lng});"
                    ."way[\"{$key}\"=\"{$value}\"](around:{$radius},{$lat},{$lng});"
                    ."relation[\"{$key}\"=\"{$value}\"](around:{$radius},{$lat},{$lng});"
                    .');out center;';

                $response = Http::asForm()->timeout(30)->post($endpoint, ['data' => $ql]);

                return $response->successful() ? ($response->json('elements') ?? []) : [];
            });
        } catch (Throwable) {
            return null;
        }

        $nearest = null;
        $nearestDistance = INF;

        foreach ($elements as $element) {
            $featureLat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $featureLng = $element['lon'] ?? ($element['center']['lon'] ?? null);
            if ($featureLat === null || $featureLng === null) {
                continue;
            }

            $distance = Geo::distanceMeters($lat, $lng, (float) $featureLat, (float) $featureLng);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = new GeoFeature(
                    id: ($element['type'] ?? 'node').'/'.($element['id'] ?? ''),
                    type: $type,
                    lat: (float) $featureLat,
                    lng: (float) $featureLng,
                    name: $element['tags']['name'] ?? null,
                );
            }
        }

        return $nearest;
    }
}
