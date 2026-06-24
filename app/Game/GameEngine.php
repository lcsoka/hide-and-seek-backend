<?php

namespace App\Game;

use App\Enums\SessionStatus;
use App\Events\GameEventBroadcast;
use App\Game\Support\Action;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class GameEngine
{
    public function __construct(private readonly GameModeRegistry $modes) {}

    /**
     * Validate an action against the mode, apply the outcome, persist state,
     * and append to the action log. Returns the refreshed session.
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

        $session->state_data = $outcome->stateData;
        if ($outcome->nextState !== null) {
            $session->state = $outcome->nextState;
            if ($outcome->nextState === 'finished') {
                $session->status = SessionStatus::Finished;
            }
        }
        $session->save();

        $session->actionLogs()->create([
            'player_id' => $player->id,
            'type' => $action->type,
            'payload' => $action->payload,
        ]);

        foreach ($outcome->events as $event) {
            GameEventBroadcast::dispatch($session->id, $event['type'], $event['payload'] ?? []);
        }

        return $session->refresh();
    }

    /** Resolve the acting player for an authenticated user, or 403. */
    public function playerFor(Session $session, User $user): Player
    {
        $player = $session->players()->where('user_id', $user->id)->first();

        abort_if($player === null, 403, 'Not a participant in this session.');

        return $player;
    }
}
