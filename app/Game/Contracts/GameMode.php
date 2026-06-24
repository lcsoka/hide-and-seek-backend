<?php

namespace App\Game\Contracts;

use App\Enums\GameSize;
use App\Game\Support\Action;
use App\Game\Support\ActionOutcome;
use App\Game\Support\ValidationResult;
use App\Models\Player;
use App\Models\Session;

/**
 * A pluggable game mode. The engine owns sessions/players/timers/channels and
 * calls into the mode for all rules.
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

    /**
     * Action types this player may legally submit in the session's current state.
     *
     * @return list<string>
     */
    public function availableActions(Session $session, Player $player): array;

    public function validateAction(Session $session, Player $player, Action $action): ValidationResult;

    public function applyAction(Session $session, Player $player, Action $action): ActionOutcome;

    /** Final standings when the game is over, or null while it continues. */
    public function winCondition(Session $session): ?array;
}
