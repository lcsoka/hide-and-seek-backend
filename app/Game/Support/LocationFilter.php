<?php

namespace App\Game\Support;

/**
 * The set of players whose location a given viewer is allowed to see. The engine
 * asks the mode for this before exposing any coordinates.
 */
final class LocationFilter
{
    /** @param list<string> $visiblePlayerIds */
    private function __construct(private readonly array $visiblePlayerIds) {}

    /** @param iterable<string> $ids */
    public static function only(iterable $ids): self
    {
        return new self(array_values(is_array($ids) ? $ids : iterator_to_array($ids)));
    }

    public function allows(string $playerId): bool
    {
        return in_array($playerId, $this->visiblePlayerIds, true);
    }
}
