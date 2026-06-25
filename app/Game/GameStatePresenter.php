<?php

namespace App\Game;

use App\Game\Support\Geo;
use App\Models\Curse;
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
            'join_code' => $session->join_code,
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
            // The answered-question history — geometry is the seeker's own positions and
            // the answer is just the constraint, so it never reveals the hider's location.
            'questions' => $this->questions($session),
            'curses' => $this->activeCurses($session),
            // Only the hider sees their own hiding zone + hand of curse cards.
            'hiding_zone' => ($player && $player->role === 'hider') ? ($session->state_data['hiding_zone'] ?? null) : null,
            // Whether a seeker is inside the zone (the hider can re-hide only while it's unlocked).
            'zone_locked' => ($player && $player->role === 'hider') ? $this->zoneLocked($session) : false,
            'hand' => ($player && $player->role === 'hider') ? $this->hand($session) : [],
            'timers' => $this->timers($session),
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

    /**
     * The answered-question history and active curses — reused by the debug god view
     * (and the admin replay) so it sees the same formatting as the seeker /state.
     *
     * @return array{questions: array<int, array<string, mixed>>, curses: array<int, array<string, mixed>>}
     */
    public function history(Session $session): array
    {
        return ['questions' => $this->questions($session), 'curses' => $this->activeCurses($session)];
    }

    /**
     * Answered questions, reduced to what a seeker needs to reconstruct the
     * deduction: the category, the seeker's own ask/resolve positions, and the
     * answer. The hider's location is never included.
     *
     * @return array<int, array<string, mixed>>
     */
    private function questions(Session $session): array
    {
        $resolved = $session->state_data['questions'] ?? [];

        return array_map(function (array $q): array {
            $payload = $q['payload'] ?? [];

            return [
                'seq' => $q['seq'] ?? null,
                'category' => $q['category'] ?? null,
                'question_id' => $q['question_id'] ?? null,
                'asked_by' => $q['asked_by'] ?? null,
                'asked_at' => $q['asked_at'] ?? null,
                'resolved_at' => $q['resolved_at'] ?? null,
                'auto' => $q['auto'] ?? false,
                'answer' => $q['answer'] ?? null,
                'ask' => [
                    'lat' => $payload['ask_lat'] ?? $payload['start_lat'] ?? null,
                    'lng' => $payload['ask_lng'] ?? $payload['start_lng'] ?? null,
                    'radius_m' => $payload['radius_m'] ?? null,
                    'feature' => $payload['feature'] ?? null,
                    'start_lat' => $payload['start_lat'] ?? null,
                    'start_lng' => $payload['start_lng'] ?? null,
                ],
                'end' => ['lat' => $q['end_lat'] ?? null, 'lng' => $q['end_lng'] ?? null],
            ];
        }, $resolved);
    }

    /** True if any seeker is within the hider's zone — re-hiding is locked while so. */
    private function zoneLocked(Session $session): bool
    {
        $zone = $session->state_data['hiding_zone'] ?? null;
        $center = $zone['center'] ?? null;
        if ($center === null) {
            return false;
        }
        $radius = (float) ($zone['radius_m'] ?? 0);

        return $session->players->contains(fn (Player $p) => $p->role === 'seeker'
            && $p->last_lat !== null && $p->last_lng !== null
            && Geo::distanceMeters((float) $p->last_lat, (float) $p->last_lng, (float) $center['lat'], (float) $center['lng']) <= $radius);
    }

    /**
     * The hider's hand of curse cards (curse_ids resolved to name/cost/description).
     *
     * @return array<int, array<string, mixed>>
     */
    private function hand(Session $session): array
    {
        $ids = $session->state_data['hand'] ?? [];
        $models = Curse::whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id');

        return array_map(fn ($id) => [
            'curse_id' => $id,
            'name' => $models->get($id)?->name,
            'cost' => $models->get($id)?->cost,
            'description' => $models->get($id)?->description,
        ], $ids);
    }

    /**
     * Curses played in the current round, with their name/cost resolved.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeCurses(Session $session): array
    {
        $round = $session->state_data['round'] ?? 0;
        $played = collect($session->state_data['curses_played'] ?? [])
            ->filter(fn ($c) => ($c['round'] ?? 0) === $round)
            ->values();

        $models = Curse::whereIn('id', $played->pluck('curse_id')->filter()->unique()->all())->get()->keyBy('id');

        return $played->map(fn ($c) => [
            'curse_id' => $c['curse_id'] ?? null,
            'by' => $c['by'] ?? null,
            'at' => $c['at'] ?? null,
            'name' => isset($c['curse_id']) ? $models->get($c['curse_id'])?->name : null,
            'cost' => isset($c['curse_id']) ? $models->get($c['curse_id'])?->cost : null,
        ])->all();
    }

    /**
     * Deadlines the client needs for countdowns, plus the server's clock so the
     * client can correct for drift.
     *
     * @return array<string, int>
     */
    private function timers(Session $session): array
    {
        $data = $session->state_data ?? [];

        return array_filter([
            'now' => now()->timestamp,
            'hiding_started_at' => $data['hiding_started_at'] ?? null,
            'hiding_deadline' => $data['hiding_deadline'] ?? null,
            'seeking_started_at' => $data['seeking_started_at'] ?? null,
            'question_deadline' => $data['pending_question']['deadline'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
