<?php

namespace App\Game\Geo;

/**
 * Source of OpenStreetMap features for the geographic question evaluators.
 * Swappable: Overpass API (default), a local PostGIS import later, or a fake in tests.
 */
interface MapDataSource
{
    /** The nearest feature of $type to the point, or null if none / data unavailable. */
    public function nearest(string $type, float $lat, float $lng): ?GeoFeature;
}
