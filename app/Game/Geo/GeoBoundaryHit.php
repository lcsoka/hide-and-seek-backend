<?php

namespace App\Game\Geo;

/** The nearest point on an administrative boundary line, and the distance to it (metres). */
final class GeoBoundaryHit
{
    public function __construct(
        public readonly float $distanceM,
        public readonly float $lat,
        public readonly float $lng,
    ) {}
}
