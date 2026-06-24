<?php

namespace App\Game\Geo;

final class GeoFeature
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?string $name = null,
    ) {}
}
