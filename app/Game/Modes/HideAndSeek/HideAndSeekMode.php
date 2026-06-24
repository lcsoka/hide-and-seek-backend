<?php

namespace App\Game\Modes\HideAndSeek;

use App\Enums\GameSize;
use App\Game\Contracts\GameMode;
use App\Game\Support\Action;
use App\Game\Support\ActionOutcome;
use App\Game\Support\Geo;
use App\Game\Support\LocationFilter;
use App\Game\Support\ValidationResult;
use App\Models\Player;
use App\Models\Session;

/**
 * States: lobby -> role_assignment -> hiding -> seeking -> endgame
 *         -> round_end -> (role_assignment | finished)
 */
class HideAndSeekMode implements GameMode
{
    public function key(): string
    {
        return 'hide_and_seek';
    }

    public function displayName(): string
    {
        return __('enums.game_mode.hide_and_seek');
    }

    public function defaultConfig(GameSize $size): array
    {
        return [
            'game_size' => $size->value,
            'rounds' => 3,
            'play_radius_km' => $size->playRadiusKm(),
            'hiding_time_limit_s' => $size->hidingTimeLimitSeconds(),
            'endgame_radius_m' => 500,
            'question_cooldown_s' => 300,
            'time_bonus_s' => $size->timeBonusSeconds(),
        ];
    }

    public function initialState(): string
    {
        return 'lobby';
    }

    public function initialStateData(): array
    {
        return ['round' => 0, 'scores' => []];
    }

    public function availableActions(Session $session, Player $player): array
    {
        return match ($session->state) {
            'lobby' => $player->is_host ? ['start'] : [],
            'role_assignment' => $player->is_host ? ['assign_hider'] : [],
            'hiding' => $player->role === 'hider' ? ['confirm_hidden'] : [],
            'seeking' => match ($player->role) {
                'seeker' => ['ask_question', 'declare_endgame'],
                'hider' => ['answer_question', 'play_curse'],
                default => [],
            },
            'endgame' => match ($player->role) {
                'seeker' => ['make_guess'],
                'hider' => ['surrender'],
                default => [],
            },
            'round_end' => $player->is_host ? ['advance_round'] : [],
            default => [],
        };
    }

    public function validateAction(Session $session, Player $player, Action $action): ValidationResult
    {
        return match ($action->type) {
            // player_id is optional — when omitted a random hider is chosen.
            'assign_hider' => (! isset($action->payload['player_id']) || $session->players()->whereKey($action->payload['player_id'])->exists())
                ? ValidationResult::pass()
                : ValidationResult::fail('player_id must be a player in this session.'),
            'make_guess' => (isset($action->payload['lat'], $action->payload['lng']))
                ? ValidationResult::pass()
                : ValidationResult::fail('make_guess requires lat and lng.'),
            default => ValidationResult::pass(),
        };
    }

    public function applyAction(Session $session, Player $player, Action $action): ActionOutcome
    {
        $data = $session->state_data ?? [];

        return match ($action->type) {
            'start' => new ActionOutcome($data, 'role_assignment',
                [$this->event('RoundStarted', ['round' => $data['round'] ?? 0])]),

            'assign_hider' => $this->assignHider($session, $action, $data),
            'confirm_hidden' => $this->confirmHidden($data),
            'ask_question' => $this->logged($data, 'questions', ['asked_by' => $player->id] + $action->payload, 'QuestionAsked', $action->payload),
            'answer_question' => $this->logged($data, 'answers', ['by' => $player->id] + $action->payload, 'QuestionAnswered', $action->payload),
            'play_curse' => $this->logged($data, 'curses_played', ['by' => $player->id] + $action->payload, 'CursePlayed', $action->payload),
            'declare_endgame' => new ActionOutcome($data, 'endgame', [$this->event('EndgameTriggered', ['by' => $player->id])]),
            'make_guess' => $this->makeGuess($session, $player, $action, $data),
            'surrender' => $this->endRound($data, [$this->event('HiderFound', ['round' => $data['round'] ?? 0, 'surrendered' => true])]),
            'advance_round' => $this->advanceRound($session, $data),

            default => new ActionOutcome($data),
        };
    }

    public function winCondition(Session $session): ?array
    {
        return $session->state === 'finished'
            ? ['standings' => $this->standings($session->state_data ?? [])]
            : null;
    }

    public function locationVisibility(Session $session, Player $viewer): LocationFilter
    {
        $allIds = $session->players->pluck('id')->all();

        // The hider's position is concealed from seekers while the round is live.
        $concealed = in_array($session->state, ['hiding', 'seeking', 'endgame'], true);
        if (! $concealed) {
            return LocationFilter::only($allIds);
        }

        $hiderId = $session->state_data['hider_id'] ?? null;

        // The hider sees everyone; seekers see everyone except the hider.
        if ($viewer->role === 'hider' || $viewer->id === $hiderId) {
            return LocationFilter::only($allIds);
        }

        return LocationFilter::only(array_filter($allIds, fn ($id) => $id !== $hiderId));
    }

    // ── handlers ────────────────────────────────────────────────────────────

    private function assignHider(Session $session, Action $action, array $data): ActionOutcome
    {
        // Host may pick the first hider, or omit player_id for a random one.
        $hiderId = $action->payload['player_id'] ?? $session->players()->inRandomOrder()->value('id');
        $session->players()->update(['role' => 'seeker']);
        $session->players()->whereKey($hiderId)->update(['role' => 'hider']);

        $limit = (int) ($session->config['hiding_time_limit_s'] ?? 1800);
        $data['hider_id'] = $hiderId;
        $data['hiding_started_at'] = now()->timestamp;
        $data['hiding_deadline'] = now()->addSeconds($limit)->timestamp;

        return new ActionOutcome($data, 'hiding',
            [$this->event('HidingStarted', ['round' => $data['round'] ?? 0, 'hiding_deadline' => $data['hiding_deadline']])]);
    }

    private function confirmHidden(array $data): ActionOutcome
    {
        $data['seeking_started_at'] = now()->timestamp;

        return new ActionOutcome($data, 'seeking', [$this->event('SeekingStarted', ['round' => $data['round'] ?? 0])]);
    }

    private function logged(array $data, string $bucket, array $entry, string $event, array $payload): ActionOutcome
    {
        $entry['at'] = now()->timestamp;
        $data[$bucket][] = $entry;

        return new ActionOutcome($data, null, [$this->event($event, $payload)]);
    }

    private function makeGuess(Session $session, Player $player, Action $action, array $data): ActionOutcome
    {
        $hider = $session->players()->find($data['hider_id'] ?? null);
        $radius = (float) ($session->config['endgame_radius_m'] ?? 500);

        $correct = $hider && $hider->last_lat !== null && $hider->last_lng !== null
            && Geo::distanceMeters((float) $action->payload['lat'], (float) $action->payload['lng'], (float) $hider->last_lat, (float) $hider->last_lng) <= $radius;

        if ($correct) {
            return $this->endRound($data, [$this->event('HiderFound', ['round' => $data['round'] ?? 0, 'found_by' => $player->id])]);
        }

        return new ActionOutcome($data, null, [$this->event('GuessMissed', ['by' => $player->id])]);
    }

    private function endRound(array $data, array $events): ActionOutcome
    {
        $hiderId = $data['hider_id'] ?? null;
        $seconds = max(0, now()->timestamp - (int) ($data['hiding_started_at'] ?? now()->timestamp));
        if ($hiderId !== null) {
            $data['scores'][$hiderId] = ($data['scores'][$hiderId] ?? 0) + $seconds;
        }
        $data['last_round_seconds'] = $seconds;

        return new ActionOutcome($data, 'round_end', $events);
    }

    private function advanceRound(Session $session, array $data): ActionOutcome
    {
        $completed = ($data['round'] ?? 0) + 1;
        $rounds = (int) ($session->config['rounds'] ?? 3);

        if ($completed >= $rounds) {
            return new ActionOutcome($data, 'finished', [$this->event('GameEnded', ['standings' => $this->standings($data)])]);
        }

        $session->players()->update(['role' => null]);
        $data['round'] = $completed;
        unset($data['hider_id'], $data['hiding_started_at'], $data['hiding_deadline'], $data['seeking_started_at']);

        return new ActionOutcome($data, 'role_assignment', [$this->event('RoundStarted', ['round' => $completed])]);
    }

    private function standings(array $data): array
    {
        $scores = $data['scores'] ?? [];
        arsort($scores);

        $rank = 1;
        $standings = [];
        foreach ($scores as $playerId => $seconds) {
            $standings[] = ['player_id' => $playerId, 'total_hiding_time_s' => $seconds, 'rank' => $rank++];
        }

        return $standings;
    }

    private function event(string $type, array $payload): array
    {
        return ['type' => $type, 'payload' => $payload];
    }
}
