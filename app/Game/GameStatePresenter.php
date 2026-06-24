<?php

namespace App\Game;

use App\Models\Player;
use App\Models\Session;

class GameStatePresenter
{
    public function __construct(private readonly GameModeRegistry $modes) {}

    /**
     * The game view for a given player (available actions are player-specific).
     *
     * @return array<string, mixed>
     */
    public function present(Session $session, ?Player $player = null): array
    {
        $session->loadMissing('players', 'teams');
        $mode = $this->modes->make($session->game_mode->value);
        $filter = $player ? $mode->locationVisibility($session, $player) : null;

        return [
            'session_id' => $session->id,
            'game_mode' => $session->game_mode?->value,
            'state' => $session->state,
            'status' => $session->status?->value,
            'round' => $session->state_data['round'] ?? 0,
            'config' => $session->config,
            'players' => $session->players->map(function (Player $p) use ($filter) {
                $visible = $filter?->allows($p->id) ?? false;

                return [
                    'id' => $p->id,
                    'display_name' => $p->display_name,
                    'role' => $p->role,
                    'is_host' => $p->is_host,
                    'team_id' => $p->team_id,
                    'lat' => $visible ? $p->last_lat : null,
                    'lng' => $visible ? $p->last_lng : null,
                    'last_location_at' => $visible ? $p->last_location_at : null,
                ];
            })->values(),
            'teams' => $session->teams->map(fn ($team) => [
                'id' => $team->id, 'name' => $team->name, 'color' => $team->color,
            ]),
            'available_actions' => $player ? $mode->availableActions($session, $player) : [],
            'pending_question' => $this->pendingQuestion($session),
            // Only the hider sees their own hiding zone.
            'hiding_zone' => ($player && $player->role === 'hider') ? ($session->state_data['hiding_zone'] ?? null) : null,
            'timers' => [],
        ];
    }

    /** The open question (if any) — with the server-held truth stripped out. */
    private function pendingQuestion(Session $session): ?array
    {
        $pending = $session->state_data['pending_question'] ?? null;

        if ($pending === null) {
            return null;
        }

        return [
            'seq' => $pending['seq'] ?? null,
            'question_id' => $pending['question_id'] ?? null,
            'category' => $pending['category'] ?? null,
            'asked_by' => $pending['asked_by'] ?? null,
            'deadline' => $pending['deadline'] ?? null,
        ];
    }
}
