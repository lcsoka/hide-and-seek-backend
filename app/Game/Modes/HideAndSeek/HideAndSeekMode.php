<?php

namespace App\Game\Modes\HideAndSeek;

use App\Enums\GameSize;
use App\Enums\QuestionCategory;
use App\Game\Contracts\GameMode;
use App\Game\Geo\MapDataSource;
use App\Game\Questions\DeferredQuestionEvaluator;
use App\Game\Questions\QuestionEvaluatorRegistry;
use App\Game\Support\Action;
use App\Game\Support\ActionOutcome;
use App\Game\Support\Geo;
use App\Game\Support\LocationFilter;
use App\Game\Support\ValidationResult;
use App\Jobs\ComputeQuestionTruth;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * States: lobby -> role_assignment -> hiding -> seeking -> endgame
 *         -> round_end -> (role_assignment | finished)
 */
class HideAndSeekMode implements GameMode
{
    public function __construct(
        private readonly QuestionEvaluatorRegistry $evaluators,
        private readonly MapDataSource $map,
    ) {}

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
            'question_answer_time_s' => 600, // hider's window to answer a question (10 min)
            'hiding_zone_radius_m' => $size->hidingZoneRadiusMeters(),
            'hiding_zone_rule' => config('game.hiding_zone.default_rule', 'circle'),
            'time_bonus_s' => $size->timeBonusSeconds(),
            'units' => 'metric', // 'metric' | 'imperial' — display preference for the clients
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
        $pending = ($session->state_data['pending_question'] ?? null) !== null;

        $actions = match ($session->state) {
            'lobby' => $player->is_host ? ['start'] : [],
            'role_assignment' => $player->is_host ? ['assign_hider'] : [],
            'hiding' => $player->role === 'hider' ? ['choose_station', 'confirm_hidden'] : [],
            'seeking' => match ($player->role) {
                // A seeker can't ask while a question is awaiting the hider's answer.
                'seeker' => $pending ? ['declare_endgame'] : ['ask_question', 'declare_endgame'],
                // The hider answers only while a question is pending; can always curse.
                'hider' => $pending ? ['answer_question', 'play_curse'] : ['play_curse'],
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

        // The host can stop the game at any point (e.g. to avoid leaving it abandoned).
        if ($player->is_host && $session->state !== 'finished') {
            $actions[] = 'end_game';
        }

        return $actions;
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
            'ask_question' => ($session->state_data['pending_question'] ?? null) === null
                ? ValidationResult::pass()
                : ValidationResult::fail('A question is already awaiting an answer.'),
            'answer_question' => ($session->state_data['pending_question'] ?? null) !== null
                ? ValidationResult::pass()
                : ValidationResult::fail('There is no question to answer.'),
            'choose_station' => (isset($action->payload['lat'], $action->payload['lng']))
                ? ValidationResult::pass()
                : ValidationResult::fail('choose_station requires the station lat and lng.'),
            'confirm_hidden' => $this->validateWithinHidingZone($session, $player),
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
            'choose_station' => $this->chooseStation($session, $player, $action, $data),
            'confirm_hidden' => $this->confirmHidden($data),
            'ask_question' => $this->askQuestion($session, $player, $action, $data),
            'answer_question' => $this->answerQuestion($session, $action, $data),
            'play_curse' => $this->logged($data, 'curses_played', ['by' => $player->id, 'round' => $data['round'] ?? 0] + $action->payload, 'CursePlayed', $action->payload),
            'declare_endgame' => new ActionOutcome($data, 'endgame', [$this->event('EndgameTriggered', ['by' => $player->id])]),
            'make_guess' => $this->makeGuess($session, $player, $action, $data),
            'surrender' => $this->endRound($data, [$this->event('HiderFound', ['round' => $data['round'] ?? 0, 'surrendered' => true])]),
            'advance_round' => $this->advanceRound($session, $data),
            'end_game' => new ActionOutcome(
                array_merge($data, ['ended_reason' => 'host_ended']),
                'finished',
                [$this->event('GameEnded', ['standings' => $this->standings($data), 'reason' => 'host_ended'])],
            ),

            default => new ActionOutcome($data),
        };
    }

    public function winCondition(Session $session): ?array
    {
        return $session->state === 'finished'
            ? ['standings' => $this->standings($session->state_data ?? [])]
            : null;
    }

    public function onTimerExpired(Session $session, string $timerKey): ActionOutcome
    {
        $data = $session->state_data ?? [];

        // Hiding time ran out: force the transition to seeking. Guarded by state so
        // an early confirm_hidden (already in seeking) makes this a no-op.
        if ($timerKey === 'hiding_deadline' && $session->state === 'hiding') {
            return $this->confirmHidden($data);
        }

        // The hider didn't answer in time: auto-resolve with the server truth.
        if ($timerKey === 'question_answer' && ($data['pending_question'] ?? null) !== null) {
            return $this->resolveQuestion($session, $data, null, auto: true);
        }

        return new ActionOutcome($data);
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

        return new ActionOutcome(
            $data,
            'hiding',
            [$this->event('HidingStarted', ['round' => $data['round'] ?? 0, 'hiding_deadline' => $data['hiding_deadline']])],
            [['op' => 'set', 'key' => 'hiding_deadline', 'delay' => $limit]],
        );
    }

    private function confirmHidden(array $data): ActionOutcome
    {
        $data['seeking_started_at'] = now()->timestamp;

        return new ActionOutcome($data, 'seeking', [$this->event('SeekingStarted', ['round' => $data['round'] ?? 0])]);
    }

    /**
     * The hider designates their station; this becomes the centre of the hiding
     * zone. The centre is NOT broadcast (only the hider sees it via /state).
     */
    private function chooseStation(Session $session, Player $hider, Action $action, array $data): ActionOutcome
    {
        $lat = (float) $action->payload['lat'];
        $lng = (float) $action->payload['lng'];
        $radius = (float) ($session->config['hiding_zone_radius_m'] ?? 400);
        $rule = $session->config['hiding_zone_rule'] ?? 'circle';

        $zone = ['center' => ['lat' => $lat, 'lng' => $lng], 'radius_m' => $radius, 'rule' => $rule];

        // For the 'nearest' rule, include neighbouring stations so the client can
        // draw the carved (clipped-Voronoi) zone.
        if ($rule === 'nearest') {
            $feature = config('game.hiding_zone.station_feature', 'rail_station');
            $zone['neighbors'] = array_map(
                fn ($f) => ['id' => $f->id, 'lat' => $f->lat, 'lng' => $f->lng],
                $this->map->within($feature, $lat, $lng, $radius * 2),
            );
        }

        $data['hiding_zone'] = $zone;

        // Player-scoped: only the hider receives their zone (with coordinates).
        return new ActionOutcome($data, null, [$this->playerEvent('HidingZoneChosen', $zone, $hider->id)]);
    }

    private function validateWithinHidingZone(Session $session, Player $hider): ValidationResult
    {
        $zone = $session->state_data['hiding_zone'] ?? null;
        if ($zone === null) {
            return ValidationResult::pass(); // no station chosen yet — not enforced
        }

        if ($hider->last_lat === null || $hider->last_lng === null) {
            return ValidationResult::fail('Report your location before confirming.');
        }

        $center = $zone['center'];
        $toChosen = Geo::distanceMeters((float) $hider->last_lat, (float) $hider->last_lng, (float) $center['lat'], (float) $center['lng']);
        if ($toChosen > (float) $zone['radius_m']) {
            return ValidationResult::fail('You are outside your hiding zone.');
        }

        if (($zone['rule'] ?? 'circle') === 'nearest') {
            $feature = config('game.hiding_zone.station_feature', 'rail_station');
            $nearest = $this->map->nearest($feature, (float) $hider->last_lat, (float) $hider->last_lng);
            if ($nearest !== null) {
                $toNearest = Geo::distanceMeters((float) $hider->last_lat, (float) $hider->last_lng, $nearest->lat, $nearest->lng);
                if ($toChosen > $toNearest + 1.0) {
                    return ValidationResult::fail('Another station is closer — you are outside your hiding zone.');
                }
            }
        }

        return ValidationResult::pass();
    }

    private function askQuestion(Session $session, Player $asker, Action $action, array $data): ActionOutcome
    {
        $payload = $action->payload;
        // Capture the seeker's ask-time position (radar centre / thermometer A). It's
        // the seeker's OWN location, so it is safe to expose to seekers later.
        $payload['ask_lat'] = $asker->last_lat;
        $payload['ask_lng'] = $asker->last_lng;
        $question = isset($payload['question_id']) ? Question::find($payload['question_id']) : null;
        $evaluator = $question !== null ? $this->evaluators->for($question->category) : null;

        // Persist the question's geometry inputs (OSM feature type / radius) so seekers
        // can reconstruct matching/measuring/tentacles regions from /state.
        if ($question !== null) {
            $payload['feature'] = $payload['feature'] ?? ($question->parameters['feature'] ?? null);
            $payload['radius_m'] = $payload['radius_m'] ?? ($question->parameters['radius_m'] ?? null);
        }

        $window = (int) ($session->config['question_answer_time_s'] ?? 600);
        $seq = ($data['question_seq'] ?? 0) + 1;

        // Deferred (thermometer): capture the seeker's start position; resolve later.
        // Overpass-backed categories (matching/measuring/tentacles) compute truth in a
        // queued job so the ask returns immediately. Radar is pure geometry → inline.
        $truth = null;
        $jobs = [];
        if ($evaluator instanceof DeferredQuestionEvaluator) {
            $payload['start_lat'] = $asker->last_lat;
            $payload['start_lng'] = $asker->last_lng;
        } elseif ($evaluator !== null) {
            if (in_array($question->category->value, ['matching', 'measuring', 'tentacles'], true)) {
                $jobs[] = new ComputeQuestionTruth($session->id, $seq);
            } else {
                $truth = $evaluator->evaluate($session, $asker, $question, $payload);
            }
        }

        $data['question_seq'] = $seq;
        $data['pending_question'] = [
            'seq' => $seq,
            'question_id' => $question?->id,
            'category' => $question?->category->value,
            'asked_by' => $asker->id,
            'payload' => $payload,
            'asked_at' => now()->timestamp,
            'deadline' => now()->addSeconds($window)->timestamp,
            'truth' => $truth, // server-only; never broadcast or exposed via /state
        ];
        $data['question_answer'] = $seq; // timer guard (cleared on resolve)

        return new ActionOutcome(
            $data,
            null,
            [$this->event('QuestionAsked', [
                'seq' => $seq,
                'question_id' => $question?->id,
                'category' => $question?->category->value,
                'asked_by' => $asker->id,
                'deadline' => $data['pending_question']['deadline'],
            ])],
            [['op' => 'set', 'key' => 'question_answer', 'delay' => $window]],
            $jobs,
        );
    }

    /**
     * Fill in the pending question's authoritative answer (called by ComputeQuestionTruth
     * off the request path). Guarded by seq so a superseded/answered question is skipped.
     */
    public function computePendingTruth(Session $session, int $seq): void
    {
        $data = $session->state_data ?? [];
        $pending = $data['pending_question'] ?? null;
        if ($pending === null || ($pending['seq'] ?? null) !== $seq || ($pending['truth'] ?? null) !== null) {
            return;
        }

        $truth = $this->evaluateTruth($session, $pending);
        if ($truth !== null) {
            $data['pending_question']['truth'] = $truth;
            $session->state_data = $data;
            $session->save();
        }
    }

    /** Run the non-deferred evaluator for a pending question (radar inline / OSM via job). */
    private function evaluateTruth(Session $session, array $pending): ?array
    {
        $category = isset($pending['category']) ? QuestionCategory::tryFrom($pending['category']) : null;
        $evaluator = $category ? $this->evaluators->for($category) : null;
        if ($evaluator === null || $evaluator instanceof DeferredQuestionEvaluator) {
            return null;
        }

        $asker = $session->players()->find($pending['asked_by'] ?? null);
        $question = isset($pending['question_id']) ? Question::find($pending['question_id']) : null;
        if ($asker === null || $question === null) {
            return null;
        }

        return $evaluator->evaluate($session, $asker, $question, $pending['payload'] ?? []);
    }

    private function answerQuestion(Session $session, Action $action, array $data): ActionOutcome
    {
        return $this->resolveQuestion($session, $data, $action->payload['answer'] ?? null, auto: false);
    }

    /**
     * Finalise the pending question: reveal the server truth (ask-time evaluable),
     * compute a deferred answer (thermometer), or accept the hider's answer.
     */
    private function resolveQuestion(Session $session, array $data, mixed $hiderAnswer, bool $auto): ActionOutcome
    {
        $pending = $data['pending_question'] ?? null;
        if ($pending === null) {
            return new ActionOutcome($data);
        }

        // Prefer the pre-computed truth; if the job hasn't landed yet, compute it now
        // (Overpass is likely warm from the job's attempt), then deferred, then manual.
        $answer = $pending['truth']
            ?? $this->evaluateTruth($session, $pending)
            ?? $this->deferredAnswer($session, $pending)
            ?? ['answer' => $hiderAnswer];

        // The seeker's resolve-time position (thermometer B) — their own location.
        $asker = $session->players()->find($pending['asked_by'] ?? null);

        $resolved = $pending + [
            'answer' => $answer,
            'resolved_at' => now()->timestamp,
            'auto' => $auto,
            'end_lat' => $asker?->last_lat,
            'end_lng' => $asker?->last_lng,
        ];
        unset($resolved['truth']);

        $data['questions'][] = $resolved;
        $data['pending_question'] = null;
        $data['question_answer'] = null; // invalidate the deadline timer

        return new ActionOutcome($data, null, [
            $this->event('QuestionAnswered', ['seq' => $pending['seq'], 'question_id' => $pending['question_id'], 'auto' => $auto] + $answer),
        ]);
    }

    /** Run a deferred evaluator (thermometer) against the seeker's current position. */
    private function deferredAnswer(Session $session, array $pending): ?array
    {
        $category = isset($pending['category']) ? QuestionCategory::tryFrom($pending['category']) : null;
        $evaluator = $category ? $this->evaluators->for($category) : null;
        if (! $evaluator instanceof DeferredQuestionEvaluator) {
            return null;
        }

        $asker = $session->players()->find($pending['asked_by'] ?? null);
        $question = isset($pending['question_id']) ? Question::find($pending['question_id']) : null;
        if ($asker === null || $question === null) {
            return null;
        }

        return $evaluator->evaluate($session, $asker, $question, $pending['payload'] ?? []);
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

    /** A player-scoped event — broadcast only on that player's private channel. */
    private function playerEvent(string $type, array $payload, string $playerId): array
    {
        return ['type' => $type, 'payload' => $payload, 'visibility' => ['scope' => 'player', 'player_id' => $playerId]];
    }
}
