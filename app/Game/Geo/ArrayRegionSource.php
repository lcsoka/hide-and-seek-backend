<?php

namespace App\Game\Geo;

use Closure;

/**
 * In-memory RegionSource for tests + a safe (empty) default binding. Pass closures that resolve
 * a containing area / nearest boundary from a point, so a test can make the hider and seeker land
 * in different areas or at different distances.
 */
final class ArrayRegionSource implements RegionSource
{
    /**
     * @param  (Closure(float, float, int): ?GeoArea)|null  $areaFn
     * @param  (Closure(float, float, int): ?GeoBoundaryHit)|null  $boundaryFn
     */
    public function __construct(
        private readonly ?Closure $areaFn = null,
        private readonly ?Closure $boundaryFn = null,
    ) {}

    public function areaContaining(float $lat, float $lng, int $adminLevel): ?GeoArea
    {
        return $this->areaFn ? ($this->areaFn)($lat, $lng, $adminLevel) : null;
    }

    public function nearestBoundary(float $lat, float $lng, int $adminLevel): ?GeoBoundaryHit
    {
        return $this->boundaryFn ? ($this->boundaryFn)($lat, $lng, $adminLevel) : null;
    }
}
