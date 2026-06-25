<?php

namespace App\Game;

use App\Game\Contracts\GameMode;
use App\Game\Modes\HideAndSeek\HideAndSeekMode;
use App\Game\Support\Geo;
use App\Models\Curse;
use App\Models\Player;
use App\Models\Question;
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
        $isHider = $player && $player->role === 'hider';

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
            'pending_question' => $this->pendingQuestion($session, $isHider, $mode),
            // A thermometer the seeker has started but not yet stopped (shared so all see it).
            'thermometer' => $session->state_data['thermometer'] ?? null,
            // The answered-question history — geometry is the seeker's own positions and
            // the answer is just the constraint, so it never reveals the hider's location.
            'questions' => $this->questions($session),
            'curses' => $this->activeCurses($session),
            // Only the hider sees their own hiding zone, hand, draw, and banked time.
            'hiding_zone' => $isHider ? ($session->state_data['hiding_zone'] ?? null) : null,
            'zone_locked' => $isHider ? $this->zoneLocked($session) : false,
            // The hider played 'move' and must re-confirm their new spot.
            'relocating' => $isHider ? (bool) ($session->state_data['relocating'] ?? false) : false,
            'hand' => $isHider ? $this->hand($session) : [],
            'pending_draw' => $isHider ? $this->pendingDraw($session) : null,
            'time_bonus_s' => $isHider ? $this->handTimeBonusSeconds($session) : null,
            'timers' => $this->timers($session),
        ];
    }

    /**
     * The open question (if any). The server-held truth is stripped, BUT the hider is
     * shown the full question and the answer they're about to give (their preview), so
     * they can confirm knowingly. Seekers see only the bare metadata.
     */
    private function pendingQuestion(Session $session, bool $isHider, GameMode $mode): ?array
    {
        $pending = $session->state_data['pending_question'] ?? null;
        if ($pending === null) {
            return null;
        }

        $question = isset($pending['question_id']) ? Question::find($pending['question_id']) : null;
        $payload = $pending['payload'] ?? [];
        $truth = $isHider && $mode instanceof HideAndSeekMode ? $mode->previewAnswer($session) : null;

        return [
            'seq' => $pending['seq'] ?? null,
            'question_id' => $pending['question_id'] ?? null,
            'category' => $pending['category'] ?? null,
            'asked_by' => $pending['asked_by'] ?? null,
            'deadline' => $pending['deadline'] ?? null,
            'title' => $question?->title,
            'prompt' => $question?->prompt,
            'params' => [
                'radius_m' => $payload['radius_m'] ?? null,
                'feature' => $payload['feature'] ?? null,
            ],
            'ask' => [
                'lat' => $payload['ask_lat'] ?? $payload['start_lat'] ?? null,
                'lng' => $payload['ask_lng'] ?? $payload['start_lng'] ?? null,
            ],
            // The seeker's reference place for matching/measuring — so the hider can see
            // which object is closest to the seeker. From the seeker's confirmed pick
            // (Overpass-free) or the computed truth's feature.
            'reference' => $this->questionReference($payload, $truth),
            'preview_answer' => $truth,
        ];
    }

    /**
     * The seeker's reference place (name + coords) for a place-based question, or null.
     * Prefers the seeker's confirmed pick (always present, no Overpass) over the
     * computed truth's feature.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $truth
     */
    private function questionReference(array $payload, ?array $truth): ?array
    {
        if (isset($payload['ref_lat'], $payload['ref_lng'])) {
            return ['name' => $payload['ref_name'] ?? null, 'lat' => (float) $payload['ref_lat'], 'lng' => (float) $payload['ref_lng']];
        }
        if (isset($truth['feature_lat'], $truth['feature_lng'])) {
            return ['name' => $truth['feature_name'] ?? null, 'lat' => (float) $truth['feature_lat'], 'lng' => (float) $truth['feature_lng']];
        }

        return null;
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
                // For a thermometer the end is the seeker's STOP point (captured when they
                // stopped), not their position when the hider got round to answering.
                'end' => [
                    'lat' => $payload['end_lat'] ?? $q['end_lat'] ?? null,
                    'lng' => $payload['end_lng'] ?? $q['end_lng'] ?? null,
                ],
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
        return $this->resolveCards($session->state_data['hand'] ?? []);
    }

    /** Cards the hider just drew and must choose from (hider-only). */
    private function pendingDraw(Session $session): ?array
    {
        $draw = $session->state_data['pending_draw'] ?? null;
        if ($draw === null) {
            return null;
        }

        return [
            'keep' => (int) ($draw['keep'] ?? 1),
            'cards' => $this->resolveCards($draw['cards'] ?? []),
        ];
    }

    /** Banked time-bonus seconds from time-bonus cards in the hand (hider-only). */
    private function handTimeBonusSeconds(Session $session): int
    {
        return array_sum(array_map(
            fn ($c) => ($c['type'] ?? 'curse') === 'time_bonus' ? (int) ($c['minutes'] ?? 0) * 60 : 0,
            $session->state_data['hand'] ?? [],
        ));
    }

    /**
     * Resolve raw card descriptors (curse/time_bonus/powerup) to display cards with
     * localized names + descriptions.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<int, array<string, mixed>>
     */
    private function resolveCards(array $cards): array
    {
        $curseIds = array_values(array_filter(array_map(fn ($c) => $c['curse_id'] ?? null, $cards)));
        $models = Curse::whereIn('id', array_unique($curseIds))->get()->keyBy('id');

        return array_map(function ($card) use ($models) {
            $type = $card['type'] ?? 'curse';

            if ($type === 'time_bonus') {
                $minutes = (int) ($card['minutes'] ?? 0);

                return [
                    'uid' => $card['uid'] ?? null, 'type' => 'time_bonus', 'minutes' => $minutes,
                    'name' => __('cards.time_bonus.name', ['minutes' => $minutes]),
                    'description' => __('cards.time_bonus.description', ['minutes' => $minutes]),
                ];
            }

            if ($type === 'powerup') {
                $power = (string) ($card['power'] ?? '');

                return [
                    'uid' => $card['uid'] ?? null, 'type' => 'powerup', 'power' => $power,
                    'name' => __("cards.powerups.{$power}.name"),
                    'description' => __("cards.powerups.{$power}.description"),
                ];
            }

            $model = isset($card['curse_id']) ? $models->get($card['curse_id']) : null;

            return [
                'uid' => $card['uid'] ?? null, 'type' => 'curse', 'curse_id' => $card['curse_id'] ?? null,
                'name' => $model?->name, 'cost' => $model?->cost, 'description' => $model?->description,
            ];
        }, $cards);
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
        $now = now()->timestamp;

        return $played->map(function ($c) use ($models, $now) {
            $model = isset($c['curse_id']) ? $models->get($c['curse_id']) : null;
            $expiresAt = $c['expires_at'] ?? null;
            $status = ($c['status'] ?? 'active') === 'completed'
                ? 'completed'
                : ($expiresAt !== null && $now > $expiresAt ? 'expired' : 'active');
            $rolls = $c['rolls'] ?? [];

            return [
                'uid' => $c['uid'] ?? null,
                'curse_id' => $c['curse_id'] ?? null,
                'by' => $c['by'] ?? null,
                'at' => $c['at'] ?? null,
                'name' => $model?->name,
                'cost' => $model?->cost,
                'description' => $model?->description,
                'requires_proof' => (bool) ($c['requires_proof'] ?? false),
                'dice' => $c['dice'] ?? null,
                'last_roll' => $rolls ? end($rolls) : null,
                'expires_at' => $expiresAt,
                'status' => $status,
                'proof_url' => $c['proof_url'] ?? null,
            ];
        })->all();
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
