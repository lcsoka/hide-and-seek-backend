<?php

namespace App\Game\Geo;

use App\Game\Support\Geo;

/**
 * In-memory map data — used in tests and as a safe default (empty) binding.
 */
final class ArrayMapDataSource implements MapDataSource
{
    /** @param list<GeoFeature> $features */
    public function __construct(private array $features = []) {}

    public function add(GeoFeature $feature): void
    {
        $this->features[] = $feature;
    }

    public function nearest(string $type, float $lat, float $lng): ?GeoFeature
    {
        $nearest = null;
        $nearestDistance = INF;

        foreach ($this->features as $feature) {
            if ($feature->type !== $type) {
                continue;
            }

            $distance = Geo::distanceMeters($lat, $lng, $feature->lat, $feature->lng);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $feature;
            }
        }

        return $nearest;
    }
}
