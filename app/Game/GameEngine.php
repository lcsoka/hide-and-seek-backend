<?php

namespace App\Game;

use App\Enums\SessionStatus;
use App\Events\GameEventBroadcast;
use App\Game\Support\Action;
use App\Game\Support\ActionOutcome;
use App\Jobs\FireGameTimer;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class GameEngine
{
    public function __construct(private readonly GameModeRegistry $modes) {}

    /**
     * Validate a player action against the mode, then apply its outcome.
     */
    public function submit(Session $session, Player $player, Action $action): Session
    {
        $mode = $this->modes->make($session->game_mode->value);

        if (! in_array($action->type, $mode->availableActions($session, $player), true)) {
            throw ValidationException::withMessages([
                'type' => ["Action [{$action->type}] is not available in state [{$session->state}]."],
            ]);
        }

        $validation = $mode->validateAction($session, $player, $action);
        if (! $validation->ok) {
            throw ValidationException::withMessages(['type' => [$validation->message]]);
        }

        $outcome = $mode->applyAction($session, $player, $action);

        return $this->apply($session, $outcome, $action->type, $player->id, $action->payload);
    }

    /**
     * Fire a scheduled timer. Ignored if the timer is stale (its guard value no
     * longer matches state_data — e.g. the round advanced or the phase changed).
     */
    public function fireTimer(Session $session, string $key, ?int $guard = null): void
    {
        if ($guard !== null && (($session->state_data[$key] ?? null) !== $guard)) {
            return;
        }

        $mode = $this->modes->make($session->game_mode->value);
        $outcome = $mode->onTimerExpired($session, $key);

        $this->apply($session, $outcome, "timer:{$key}", null, []);
    }

    /** Resolve the acting player for an authenticated user, or 403. */
    public function playerFor(Session $session, User $user): Player
    {
        $player = $session->players()->where('user_id', $user->id)->first();

        abort_if($player === null, 403, 'Not a participant in this session.');

        return $player;
    }

    /**
     * Persist an outcome, log it, broadcast its events, and schedule its timers.
     *
     * @param  array<string, mixed>  $logPayload
     */
    private function apply(Session $session, ActionOutcome $outcome, string $logType, ?string $playerId, array $logPayload): Session
    {
        $session->state_data = $outcome->stateData;
        if ($outcome->nextState !== null) {
            $session->state = $outcome->nextState;
            if ($outcome->nextState === 'finished') {
                $session->status = SessionStatus::Finished;
            }
        }
        $session->save();

        $session->actionLogs()->create([
            'player_id' => $playerId,
            'type' => $logType,
            'payload' => $logPayload,
        ]);

        foreach ($outcome->events as $event) {
            GameEventBroadcast::dispatch($session->id, $event['type'], $event['payload'] ?? []);
        }

        foreach ($outcome->timers as $timer) {
            if (($timer['op'] ?? null) === 'set') {
                $key = $timer['key'];
                FireGameTimer::dispatch($session->id, $key, $session->state_data[$key] ?? null)
                    ->delay(now()->addSeconds((int) ($timer['delay'] ?? 0)));
            }
        }

        return $session->refresh();
    }
}
