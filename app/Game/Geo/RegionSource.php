<?php

namespace App\Game\Geo;

/**
 * Area/boundary geo queries beyond point features: which administrative area of a given level
 * contains a point (for "same division?" matching), and how far a point is from the nearest such
 * boundary line (for "closer to the border?" measuring). Mirrors MapDataSource — a live Overpass
 * backend, swappable for a local PostGIS one behind the same interface.
 */
interface RegionSource
{
    /** The administrative area of the given admin_level that contains the point (or null). */
    public function areaContaining(float $lat, float $lng, int $adminLevel): ?GeoArea;

    /** The nearest administrative boundary line of the given admin_level (2 = national border). */
    public function nearestBoundary(float $lat, float $lng, int $adminLevel): ?GeoBoundaryHit;
}
