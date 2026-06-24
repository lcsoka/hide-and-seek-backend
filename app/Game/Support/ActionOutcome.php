<?php

namespace App\Game\Support;

/**
 * The only thing the engine applies after a mode handles an action.
 */
final class ActionOutcome
{
    /**
     * @param  array<string, mixed>  $stateData  Replaces session.state_data.
     * @param  string|null  $nextState  null = stay in the current state.
     * @param  list<array<string, mixed>>  $events  Events to broadcast (Reverb wiring comes later).
     */
    public function __construct(
        public readonly array $stateData,
        public readonly ?string $nextState = null,
        public readonly array $events = [],
    ) {}
}
