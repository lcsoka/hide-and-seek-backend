<?php

namespace App\Game\Contracts;

use App\Enums\GameSize;

/**
 * A pluggable game mode. The engine owns sessions/players/timers/channels and
 * calls into the mode for all rules. This is the lifecycle/config surface used
 * by the engine-core slice; action handling (validateAction/applyAction/
 * locationVisibility/winCondition) is added as the gameplay engine is built.
 */
interface GameMode
{
    public function key(): string;

    public function displayName(): string;

    /** Resolved tunables for a new session at the given map size. */
    public function defaultConfig(GameSize $size): array;

    /** The state-machine node a new session starts in. */
    public function initialState(): string;

    /** The mode-owned state blob for a new session. */
    public function initialStateData(): array;
}
