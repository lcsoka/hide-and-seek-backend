<?php

namespace App\Game;

use App\Enums\SessionStatus;
use App\Events\GameEventBroadcast;
use App\Game\Contracts\GameMode;
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

        // Player activity keeps the session alive (abandonment clock).
        $session->last_activity_at = now();

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

    /**
     * Let the mode react to a player's freshly-reported position (e.g. proximity
     * triggers). A no-op when the mode returns null.
     */
    public function observeLocation(Session $session, Player $player): void
    {
        $mode = $this->modes->make($session->game_mode->value);
        $outcome = $mode->onLocationReported($session, $player);

        if ($outcome !== null) {
            $this->apply($session, $outcome, 'location:observed', $player->id, []);
        }
    }

    /** The game-mode handler for a session (e.g. to inspect available actions). */
    public function modeFor(Session $session): GameMode
    {
        return $this->modes->make($session->game_mode->value);
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
                $session->ended_at = now();
            } elseif ($outcome->nextState !== 'lobby' && $session->status === SessionStatus::Open) {
                $session->status = SessionStatus::Running;
            }
        }
        $session->save();

        $session->actionLogs()->create([
            'player_id' => $playerId,
            'type' => $logType,
            'payload' => $logPayload,
        ]);

        foreach ($outcome->events as $event) {
            GameEventBroadcast::record(
                $session->id,
                $event['type'],
                $event['payload'] ?? [],
                $event['visibility'] ?? ['scope' => 'everyone'],
            );
        }

        foreach ($outcome->timers as $timer) {
            if (($timer['op'] ?? null) === 'set') {
                $key = $timer['key'];
                FireGameTimer::dispatch($session->id, $key, $session->state_data[$key] ?? null)
                    ->delay(now()->addSeconds((int) ($timer['delay'] ?? 0)));
            }
        }

        // Dispatched only now that state_data is persisted, so the jobs see fresh state.
        foreach ($outcome->jobs as $job) {
            dispatch($job);
        }

        return $session->refresh();
    }
}
