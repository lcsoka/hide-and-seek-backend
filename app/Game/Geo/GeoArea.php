<?php

namespace App\Game\Geo;

/** An administrative area (megye/járás/település/kerület) that contains a point. */
final class GeoArea
{
    public function __construct(
        public readonly string $id,
        public readonly int $adminLevel,
        public readonly ?string $name = null,
    ) {}
}
