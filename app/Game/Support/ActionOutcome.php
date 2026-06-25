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
     * @param  list<array<string, mixed>>  $events  Events to broadcast.
     * @param  list<array{op: string, key: string, delay?: int}>  $timers  Timer ops to set/clear.
     * @param  list<object>  $jobs  Queued jobs to dispatch after the state is persisted.
     */
    public function __construct(
        public readonly array $stateData,
        public readonly ?string $nextState = null,
        public readonly array $events = [],
        public readonly array $timers = [],
        public readonly array $jobs = [],
    ) {}
}
