<?php

namespace App\Game\Modes\HideAndSeek;

use App\Enums\GameSize;
use App\Enums\QuestionCategory;
use App\Game\Contracts\GameMode;
use App\Game\Geo\MapDataSource;
use App\Game\Questions\DeferredQuestionEvaluator;
use App\Game\Questions\QuestionEvaluatorRegistry;
use App\Game\Questions\ResolvesHiderLocation;
use App\Game\Support\Action;
use App\Game\Support\ActionOutcome;
use App\Game\Support\Geo;
use App\Game\Support\LocationFilter;
use App\Game\Support\ValidationResult;
use App\Jobs\ComputeQuestionTruth;
use App\Models\Curse;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;
use Illuminate\Support\Str;

/**
 * States: lobby -> role_assignment -> hiding -> seeking -> endgame
 *         -> round_end -> (role_assignment | finished)
 */
class HideAndSeekMode implements GameMode
{
    use ResolvesHiderLocation;

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
            // A seeker this close to the hider's spot can catch them (the final "found".)
            'endgame_catch_radius_m' => 75,
            // A seeker must stay inside the hiding zone this long before the endgame
            // auto-starts — so briefly passing through early on never triggers it.
            'endgame_dwell_s' => 60,
            'question_cooldown_s' => 300,
            'question_answer_time_s' => 600, // hider's window to answer a question (10 min)
            'amend_window_s' => 120, // after answering, the hider can fix a manual answer this long

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
                // A seeker can't ask while a question is awaiting the hider's answer. They
                // can clear an active curse that demands photo proof, and — once they've
                // physically closed in — catch the hider.
                'seeker' => array_merge(
                    $this->seekerActions($session, $pending),
                    $this->seekerCanCatch($session, $player) ? ['confirm_found'] : [],
                ),
                // The hider is locked to their spot once seeking begins. They answer pending
                // questions and play cards — unless a 'move' powerup put them in relocating
                // mode, in which case they re-confirm their new spot.
                'hider' => array_merge(
                    $pending ? ['answer_question', 'play_curse', 'play_powerup'] : ['play_curse', 'play_powerup'],
                    ($session->state_data['pending_draw'] ?? null) !== null ? ['keep_cards'] : [],
                    ($session->state_data['relocating'] ?? false) ? ['confirm_hidden'] : [],
                    $this->amendableIndex($session) !== null ? ['amend_answer'] : [],
                ),
                default => [],
            },
            'endgame' => match ($player->role) {
                'seeker' => $this->seekerCanCatch($session, $player) ? ['confirm_found'] : [],
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
            'confirm_found' => $this->seekerCanCatch($session, $player)
                ? ValidationResult::pass()
                : ValidationResult::fail('You are not close enough to the hider to catch them.'),
            'amend_answer' => ($this->amendableIndex($session) !== null && ($action->payload['answer'] ?? null) !== null)
                ? ValidationResult::pass()
                : ValidationResult::fail('There is no recent answer to change.'),
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
            'confirm_hidden' => $this->confirmHidden($session, $data),
            'ask_question' => $this->askQuestion($session, $player, $action, $data),
            'start_thermometer' => $this->startThermometer($player, $action, $data),
            'stop_thermometer' => $this->stopThermometer($session, $player, $data),
            'answer_question' => $this->answerQuestion($session, $action, $data),
            'play_curse' => $this->playCurse($player, $action, $data),
            'play_powerup' => $this->playPowerup($action, $data),
            'keep_cards' => $this->keepCards($action, $data),
            'amend_answer' => $this->amendAnswer($session, $action, $data),
            'complete_curse' => $this->completeCurse($player, $action, $data),
            'roll_dice' => $this->rollDice($action, $data),
            'declare_endgame' => new ActionOutcome($data, 'endgame', [$this->event('EndgameTriggered', ['by' => $player->id])]),
            'confirm_found' => $this->endRound($session, $data, $player->id, surrendered: false),
            'surrender' => $this->endRound($session, $data, null, surrendered: true),
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
            return $this->confirmHidden($session, $data);
        }

        // The hider didn't answer in time: auto-resolve with the server truth.
        if ($timerKey === 'question_answer' && ($data['pending_question'] ?? null) !== null) {
            return $this->resolveQuestion($session, $data, null, auto: true);
        }

        // A seeker has dwelled in the hiding zone for the full window: start the endgame.
        // Re-checked at fire time, so a seeker who has since left (or gone stale) won't
        // trigger it. The dwell stamp is cleared either way.
        if ($timerKey === 'endgame_dwell' && $session->state === 'seeking') {
            unset($data['endgame_dwell']);
            $zone = $session->state_data['hiding_zone'] ?? null;
            $dweller = $zone !== null ? $this->seekerInZone($session, $zone, (int) ($session->config['endgame_dwell_s'] ?? 60)) : null;
            if ($dweller !== null) {
                return new ActionOutcome($data, 'endgame', [$this->event('EndgameTriggered', ['by' => $dweller, 'reason' => 'reached_zone'])]);
            }

            return new ActionOutcome($data);
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

        // The hider starts empty-handed; cards are drawn as questions are answered.
        $data['hand'] = [];

        return new ActionOutcome(
            $data,
            'hiding',
            [$this->event('HidingStarted', ['round' => $data['round'] ?? 0, 'hiding_deadline' => $data['hiding_deadline']])],
            [['op' => 'set', 'key' => 'hiding_deadline', 'delay' => $limit]],
        );
    }

    private function confirmHidden(Session $session, array $data): ActionOutcome
    {
        // Snapshot the hider's committed spot. Every question is answered against THIS
        // fixed point, so the deduction stays consistent even if the hider's live GPS
        // drifts within their zone afterwards.
        $hider = $session->players()->find($data['hider_id'] ?? null);
        if ($hider !== null && $hider->last_lat !== null && $hider->last_lng !== null) {
            $data['hider_position'] = ['lat' => (float) $hider->last_lat, 'lng' => (float) $hider->last_lng];
        }

        // Re-confirming after a 'move' powerup: just commit the new spot, stay in seeking.
        if (($data['relocating'] ?? false) && $session->state === 'seeking') {
            unset($data['relocating']);

            return new ActionOutcome($data, null, [$this->event('HiderRelocated', ['round' => $data['round'] ?? 0])]);
        }

        $data['seeking_started_at'] = now()->timestamp;

        return new ActionOutcome($data, 'seeking', [$this->event('SeekingStarted', ['round' => $data['round'] ?? 0])]);
    }

    /** The hider may re-hide (move to a new station) only while no seeker is inside their zone. */
    /**
     * The id of a seeker currently within the hider's zone (or null). When
     * $maxAgeSeconds is given, only a recently-reported position counts — so a seeker
     * whose app went silent while inside can't keep the zone "occupied" with a stale fix.
     */
    public function seekerInZone(Session $session, array $zone, ?int $maxAgeSeconds = null): ?string
    {
        $center = $zone['center'] ?? null;
        if ($center === null) {
            return null;
        }
        $radius = (float) ($zone['radius_m'] ?? 0);
        $cutoff = $maxAgeSeconds !== null ? now()->subSeconds($maxAgeSeconds * 2) : null;

        foreach ($session->players as $p) {
            if ($p->role !== 'seeker' || $p->last_lat === null || $p->last_lng === null) {
                continue;
            }
            if ($cutoff !== null && ($p->last_location_at === null || $p->last_location_at->lt($cutoff))) {
                continue;
            }
            if (Geo::distanceMeters((float) $p->last_lat, (float) $p->last_lng, (float) $center['lat'], (float) $center['lng']) <= $radius) {
                return $p->id;
            }
        }

        return null;
    }

    /**
     * After a seeker reports a position, start (or cancel) the endgame dwell clock:
     * the endgame auto-starts only once a seeker has stayed in the hiding zone for the
     * full `endgame_dwell_s`, so a brief accidental pass-through never triggers it.
     */
    public function onLocationReported(Session $session, Player $player): ?ActionOutcome
    {
        if ($session->state !== 'seeking' || $player->role !== 'seeker') {
            return null;
        }
        $zone = $session->state_data['hiding_zone'] ?? null;
        $center = $zone['center'] ?? null;
        if ($center === null || $player->last_lat === null || $player->last_lng === null) {
            return null;
        }

        $inZone = Geo::distanceMeters((float) $player->last_lat, (float) $player->last_lng, (float) $center['lat'], (float) $center['lng']) <= (float) ($zone['radius_m'] ?? 0);
        $data = $session->state_data;
        $pending = $data['endgame_dwell'] ?? null;

        if ($inZone && $pending === null) {
            $data['endgame_dwell'] = now()->timestamp; // guards the timer below
            $delay = (int) ($session->config['endgame_dwell_s'] ?? 60);

            return new ActionOutcome($data, null, [], [['op' => 'set', 'key' => 'endgame_dwell', 'delay' => $delay]]);
        }

        if (! $inZone && $pending !== null) {
            unset($data['endgame_dwell']); // left in time — the pending timer goes stale

            return new ActionOutcome($data);
        }

        return null;
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
        // Relocating via the 'move' powerup: the whole point is to leave the zone, so
        // only a known position is required — no zone bound.
        if ($session->state_data['relocating'] ?? false) {
            return ($hider->last_lat === null || $hider->last_lng === null)
                ? ValidationResult::fail('Report your location before confirming.')
                : ValidationResult::pass();
        }

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

    /** The seeker's available actions, accounting for a running thermometer. */
    private function seekerActions(Session $session, bool $pending): array
    {
        $data = $session->state_data ?? [];

        // Mid-thermometer: the seeker is travelling and can only stop it.
        if (($data['thermometer'] ?? null) !== null) {
            return ['stop_thermometer', 'declare_endgame'];
        }

        return array_merge(
            $pending ? ['declare_endgame'] : ['ask_question', 'start_thermometer', 'declare_endgame'],
            $this->curseAwaitingProof($data) ? ['complete_curse'] : [],
            $this->curseWithDiceActive($data) ? ['roll_dice'] : [],
        );
    }

    /** Begin a thermometer: capture the seeker's start position + the distance to travel. */
    private function startThermometer(Player $player, Action $action, array $data): ActionOutcome
    {
        $data['thermometer'] = [
            'asked_by' => $player->id,
            'question_id' => $action->payload['question_id'] ?? null,
            'start_lat' => $player->last_lat,
            'start_lng' => $player->last_lng,
            'distance_m' => $action->payload['distance_m'] ?? null,
            'distance_label' => $action->payload['distance_label'] ?? null,
            'started_at' => now()->timestamp,
        ];

        return new ActionOutcome($data, null, [$this->event('ThermometerStarted', ['asked_by' => $player->id])]);
    }

    /** Stop the running thermometer: capture the end, compute the result, ask the hider. */
    private function stopThermometer(Session $session, Player $player, array $data): ActionOutcome
    {
        $running = $data['thermometer'] ?? null;
        if ($running === null) {
            return new ActionOutcome($data);
        }

        $question = isset($running['question_id']) ? Question::find($running['question_id']) : null;
        $payload = [
            'ask_lat' => $running['start_lat'], 'ask_lng' => $running['start_lng'],
            'start_lat' => $running['start_lat'], 'start_lng' => $running['start_lng'],
            'end_lat' => $player->last_lat, 'end_lng' => $player->last_lng,
            'radius_m' => $running['distance_m'], 'distance_label' => $running['distance_label'],
        ];
        $truth = $this->evaluators->for(QuestionCategory::Thermometer)?->evaluate($session, $player, $question ?? new Question, $payload);

        $window = $question?->answer_time_s ?? (int) ($session->config['question_answer_time_s'] ?? 600);
        $seq = ($data['question_seq'] ?? 0) + 1;
        $data['question_seq'] = $seq;
        unset($data['thermometer']);
        $data['pending_question'] = [
            'seq' => $seq,
            'question_id' => $running['question_id'],
            'category' => 'thermometer',
            'asked_by' => $running['asked_by'],
            'payload' => $payload,
            'asked_at' => now()->timestamp,
            'deadline' => now()->addSeconds($window)->timestamp,
            'truth' => $truth,
        ];
        $data['question_answer'] = $seq;

        return new ActionOutcome(
            $data,
            null,
            [$this->event('QuestionAsked', ['seq' => $seq, 'question_id' => $running['question_id'], 'category' => 'thermometer', 'asked_by' => $running['asked_by'], 'deadline' => $data['pending_question']['deadline']])],
            [['op' => 'set', 'key' => 'question_answer', 'delay' => $window]],
        );
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

        $window = $question?->answer_time_s ?? (int) ($session->config['question_answer_time_s'] ?? 600);
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
                // Only worth a truth job when there's an OSM point feature to resolve.
                // Subjects with no feature (e.g. "international border", "sea level",
                // "coastline") aren't auto-computable — the hider answers them manually,
                // so skip the job rather than have it fail and retry forever.
                if (($payload['feature'] ?? null) !== null) {
                    $jobs[] = new ComputeQuestionTruth($session->id, $seq);
                }
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
        if ($truth === null) {
            // Overpass was unavailable / no data — let the queued job retry (backoff).
            throw new \RuntimeException("Could not compute truth for question seq {$seq} yet.");
        }

        $data['pending_question']['truth'] = $truth;
        $session->state_data = $data;
        $session->save();
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
        // Photo questions are answered with an uploaded image, not a verdict.
        $manual = isset($action->payload['photo_url'])
            ? ['answer' => 'photo', 'photo_url' => $action->payload['photo_url']]
            : ['answer' => $action->payload['answer'] ?? null];

        return $this->resolveQuestion($session, $data, $manual, auto: false);
    }

    /**
     * Finalise the pending question. Uses the pre-computed truth (radar inline / OSM via
     * the queued job / thermometer at stop) or the hider's explicit manual answer — never
     * a blocking inline Overpass call on the request path. If neither yields an answer
     * (e.g. Overpass was unavailable and the hider didn't answer manually) the question is
     * VOIDED so the seeker can ask again, rather than recording a blank answer.
     */
    /**
     * Index of the most recent question the hider may still correct: it was answered by
     * the hider's own input (manual) and resolved within `amend_window_s`. Null otherwise.
     */
    private function amendableIndex(Session $session): ?int
    {
        if ($session->state !== 'seeking') {
            return null;
        }
        $questions = $session->state_data['questions'] ?? [];
        if ($questions === []) {
            return null;
        }
        $idx = array_key_last($questions);
        $last = $questions[$idx];
        $window = (int) ($session->config['amend_window_s'] ?? 120);

        return ($last['manual'] ?? false) && (now()->timestamp - (int) ($last['resolved_at'] ?? 0)) <= $window
            ? $idx
            : null;
    }

    /** The hider corrects a manual answer they just gave (within the amend window). */
    private function amendAnswer(Session $session, Action $action, array $data): ActionOutcome
    {
        $idx = $this->amendableIndex($session);
        if ($idx === null) {
            return new ActionOutcome($data);
        }

        $new = $action->payload['answer'];
        $data['questions'][$idx]['answer']['answer'] = $new;
        $data['questions'][$idx]['amended'] = true;

        return new ActionOutcome($data, null, [
            $this->event('QuestionAmended', ['seq' => $data['questions'][$idx]['seq'] ?? null, 'answer' => $new]),
        ]);
    }

    private function resolveQuestion(Session $session, array $data, mixed $hiderAnswer, bool $auto): ActionOutcome
    {
        $pending = $data['pending_question'] ?? null;
        if ($pending === null) {
            return new ActionOutcome($data);
        }

        // Prefer the pre-computed truth, then the hider's explicit manual answer (so the
        // manual path never blocks), then a last inline compute (bounded; usually the
        // job already filled it). The /state read path never reaches here.
        $manual = is_array($hiderAnswer) ? $hiderAnswer : ['answer' => $hiderAnswer];
        $hasManual = ($manual['answer'] ?? null) !== null;
        $answer = $pending['truth']
            ?? ($hasManual ? $manual : null)
            ?? $this->evaluateTruth($session, $pending);

        // Undeterminable (e.g. throttled Overpass + no manual answer) → void, don't record blank.
        if (($answer['answer'] ?? null) === null) {
            $data['pending_question'] = null;
            $data['question_answer'] = null;

            return new ActionOutcome($data, null, [
                $this->event('QuestionVoided', ['seq' => $pending['seq'] ?? null, 'category' => $pending['category'] ?? null]),
            ]);
        }

        // Matching: always carry the seeker's reference place on the answer (the place the
        // deduction's Voronoi cell is built around) — even when answered manually, where the
        // evaluator's feature_* was never attached. The hider's own nearest is hider-only and
        // must never reach the seeker-visible history, so strip it here.
        unset($answer['hider_nearest']);
        if (($pending['category'] ?? null) === 'matching' && ($answer['feature_lat'] ?? null) === null) {
            $payload = $pending['payload'] ?? [];
            if (isset($payload['ref_lat'], $payload['ref_lng'])) {
                $answer['feature_lat'] = (float) $payload['ref_lat'];
                $answer['feature_lng'] = (float) $payload['ref_lng'];
                $answer['feature_name'] = $payload['ref_name'] ?? null;
            }
        }

        // The seeker's resolve-time position (thermometer B) — their own location.
        $asker = $session->players()->find($pending['asked_by'] ?? null);

        // True when the hider's own input set the answer (no server truth) — only these are
        // amendable, so the server-computed (anti-cheat) answers can't be overridden.
        $manualAnswer = ($pending['truth'] ?? null) === null && $hasManual;

        $resolved = $pending + [
            'answer' => $answer,
            'resolved_at' => now()->timestamp,
            'auto' => $auto,
            'manual' => $manualAnswer,
            'end_lat' => $asker?->last_lat,
            'end_lng' => $asker?->last_lng,
        ];
        unset($resolved['truth']);

        $data['questions'][] = $resolved;
        $data['pending_question'] = null;
        $data['question_answer'] = null; // invalidate the deadline timer

        // Answering rewards the hider: draw `reward_draw` cards and keep `reward_keep`.
        // The hider chooses which to keep (a draw modal); auto-resolve keeps the first few.
        $question = isset($pending['question_id']) ? Question::find($pending['question_id']) : null;
        $drawN = max(0, (int) ($question?->reward_draw ?? 1));
        $keepN = max(0, (int) ($question?->reward_keep ?? 1));
        // The Overflowing Chalice grants one extra draw on each of the next three answers.
        if (($data['bonus_draws'] ?? 0) > 0) {
            $drawN++;
            $keepN++;
            $data['bonus_draws']--;
        }
        if ($drawN > 0) {
            $drawn = $this->drawCards($drawN);
            if ($auto) {
                $data['hand'] = array_merge($data['hand'] ?? [], array_slice($drawn, 0, $keepN));
            } else {
                $data['pending_draw'] = ['cards' => $drawn, 'keep' => min($keepN, count($drawn))];
            }
        }

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

    /** The hider plays a curse card from their hand (removed by uid) against the seekers. */
    private function playCurse(Player $player, Action $action, array $data): ActionOutcome
    {
        $card = $this->takeFromHand($data, $action->payload['card_uid'] ?? null, 'curse');
        if ($card === null) {
            return new ActionOutcome($data);
        }
        $curseId = $card['curse_id'] ?? null;

        // Resolve the curse's lifecycle (a time limit and/or required photo proof).
        $curse = $curseId !== null ? Curse::find($curseId) : null;
        $params = $curse?->parameters ?? [];
        $duration = isset($params['duration_s']) ? (int) $params['duration_s'] : null;
        $now = now()->timestamp;

        $instance = [
            'uid' => (string) Str::uuid(),
            'curse_id' => $curseId,
            'by' => $player->id,
            'round' => $data['round'] ?? 0,
            'at' => $now,
            'requires_proof' => (bool) ($params['requires_proof'] ?? false),
            'dice' => $params['dice'] ?? null,
            'rolls' => [],
            'expires_at' => $duration !== null ? $now + $duration : null,
            'status' => 'active',
            'proof_url' => null,
            'completed_at' => null,
        ];

        // The Overflowing Chalice is a hider self-buff with no seeker task: grant +1 card
        // draw on the next three answers, and mark it done so it doesn't linger as active.
        if ($curse?->key === 'the_overflowing_chalice') {
            $data['bonus_draws'] = ($data['bonus_draws'] ?? 0) + 3;
            $instance['status'] = 'completed';
            $instance['completed_at'] = $now;
        }

        $data['curses_played'][] = $instance;

        return new ActionOutcome($data, null, [$this->event('CursePlayed', ['curse_id' => $curseId])]);
    }

    /** A seeker clears an active curse, attaching photo proof when the curse demands it. */
    private function completeCurse(Player $player, Action $action, array $data): ActionOutcome
    {
        $uid = $action->payload['curse_uid'] ?? null;
        $proofUrl = $action->payload['proof_url'] ?? null;

        foreach ($data['curses_played'] ?? [] as $i => $curse) {
            if (($curse['uid'] ?? null) === $uid && ($curse['status'] ?? 'active') === 'active') {
                $data['curses_played'][$i]['status'] = 'completed';
                $data['curses_played'][$i]['proof_url'] = $proofUrl;
                $data['curses_played'][$i]['completed_at'] = now()->timestamp;

                return new ActionOutcome($data, null, [
                    $this->event('CurseCompleted', ['uid' => $uid, 'by' => $player->id, 'has_proof' => $proofUrl !== null]),
                ]);
            }
        }

        return new ActionOutcome($data);
    }

    /** A seeker rolls the dice a curse requires; the (server-authoritative) result is recorded. */
    private function rollDice(Action $action, array $data): ActionOutcome
    {
        $uid = $action->payload['curse_uid'] ?? null;

        foreach ($data['curses_played'] ?? [] as $i => $curse) {
            if (($curse['uid'] ?? null) === $uid && ($curse['dice'] ?? null) !== null) {
                $dice = $curse['dice'];
                $count = max(1, (int) ($dice['count'] ?? 1));
                $sides = max(2, (int) ($dice['sides'] ?? 6));
                $target = $dice['target'] ?? null;

                $values = [];
                for ($r = 0; $r < $count; $r++) {
                    $values[] = random_int(1, $sides);
                }
                $sum = array_sum($values);
                $success = $target !== null ? $sum >= (int) $target : null;
                $data['curses_played'][$i]['rolls'][] = ['values' => $values, 'sum' => $sum, 'success' => $success, 'at' => now()->timestamp];

                // A successful roll satisfies the curse — it's cleared.
                if ($success === true) {
                    $data['curses_played'][$i]['status'] = 'completed';
                    $data['curses_played'][$i]['completed_at'] = now()->timestamp;
                }

                return new ActionOutcome($data, null, [$this->event('DiceRolled', ['uid' => $uid, 'values' => $values, 'sum' => $sum, 'success' => $success])]);
            }
        }

        return new ActionOutcome($data);
    }

    /** True if a current-round curse still has dice the seekers can roll. */
    private function curseWithDiceActive(array $data): bool
    {
        $now = now()->timestamp;
        $round = $data['round'] ?? 0;

        foreach ($data['curses_played'] ?? [] as $curse) {
            if (($curse['round'] ?? 0) === $round
                && ($curse['status'] ?? 'active') === 'active'
                && ($curse['dice'] ?? null) !== null
                && (($curse['expires_at'] ?? null) === null || $curse['expires_at'] > $now)) {
                return true;
            }
        }

        return false;
    }

    /** True if a curse in the current round still needs photo proof from the seekers. */
    private function curseAwaitingProof(array $data): bool
    {
        $now = now()->timestamp;
        $round = $data['round'] ?? 0;

        foreach ($data['curses_played'] ?? [] as $curse) {
            if (($curse['round'] ?? 0) === $round
                && ($curse['status'] ?? 'active') === 'active'
                && ! empty($curse['requires_proof'])
                && (($curse['expires_at'] ?? null) === null || $curse['expires_at'] > $now)) {
                return true;
            }
        }

        return false;
    }

    /** The hider plays a powerup card (removed by uid). Veto is the key functional one. */
    private function playPowerup(Action $action, array $data): ActionOutcome
    {
        $card = $this->takeFromHand($data, $action->payload['card_uid'] ?? null, 'powerup');
        if ($card === null) {
            return new ActionOutcome($data);
        }
        $power = $card['power'] ?? null;
        $events = [$this->event('PowerupPlayed', ['power' => $power])];

        if ($power === 'veto' && ($data['pending_question'] ?? null) !== null) {
            // Refuse the question: discard it with no answer and no draw. The seeker is
            // told so they know to ask again (rather than waiting on a silent question).
            $vetoed = $data['pending_question'];
            $data['pending_question'] = null;
            $data['question_answer'] = null;
            $events[] = $this->event('QuestionVetoed', ['seq' => $vetoed['seq'] ?? null, 'asked_by' => $vetoed['asked_by'] ?? null]);
        } elseif ($power === 'duplicate') {
            foreach ($data['hand'] ?? [] as $other) {
                if (($other['uid'] ?? null) === ($action->payload['target_uid'] ?? null)) {
                    $data['hand'][] = ['uid' => (string) Str::uuid()] + array_diff_key($other, ['uid' => true]);
                    break;
                }
            }
        } elseif ($power === 'randomize') {
            $data['hand'] = $this->drawCards(count($data['hand'] ?? []));
        } else {
            // Draw powerups: reveal the new cards through the keep-draw modal.
            $draw = ['discard_1_draw_2' => 2, 'discard_2_draw_3' => 3, 'draw_1_expand_1' => 1][$power] ?? 0;
            if ($draw > 0) {
                $cards = $this->drawCards($draw);
                $data['pending_draw'] = ['cards' => $cards, 'keep' => count($cards)];
            }
            // 'move' lets the hider relocate: drop the committed spot (questions fall back
            // to live GPS meanwhile) and require them to re-confirm their new spot.
            if ($power === 'move') {
                $data['relocating'] = true;
                unset($data['hider_position']);
            }
        }

        return new ActionOutcome($data, null, $events);
    }

    /** The hider keeps the chosen cards from a draw; the rest are discarded. */
    private function keepCards(Action $action, array $data): ActionOutcome
    {
        $draw = $data['pending_draw'] ?? null;
        if ($draw === null) {
            return new ActionOutcome($data);
        }

        $keepUids = (array) ($action->payload['uids'] ?? []);
        $kept = array_values(array_filter($draw['cards'] ?? [], fn ($c) => in_array($c['uid'] ?? null, $keepUids, true)));
        $kept = array_slice($kept, 0, (int) ($draw['keep'] ?? 0));

        $data['hand'] = array_merge($data['hand'] ?? [], $kept);
        $data['pending_draw'] = null;

        return new ActionOutcome($data, null, [$this->event('CardsKept', ['count' => count($kept)])]);
    }

    /** Remove and return the first hand card matching the uid (and type, if given). */
    private function takeFromHand(array &$data, ?string $uid, ?string $type = null): ?array
    {
        foreach ($data['hand'] ?? [] as $i => $card) {
            if (($card['uid'] ?? null) === $uid && ($type === null || ($card['type'] ?? 'curse') === $type)) {
                array_splice($data['hand'], $i, 1);

                return $card;
            }
        }

        return null;
    }

    /** Build the hider draw pool: curse cards + time-bonus + powerup cards. */
    private function deckPool(): array
    {
        $pool = [];
        foreach (Curse::query()->where('is_active', true)->pluck('id') as $id) {
            $pool[] = ['type' => 'curse', 'curse_id' => $id];
        }
        foreach ((array) config('game.hider_deck.time_bonuses', []) as $bonus) {
            for ($i = 0; $i < (int) ($bonus['count'] ?? 1); $i++) {
                $pool[] = ['type' => 'time_bonus', 'minutes' => (int) ($bonus['minutes'] ?? 0)];
            }
        }
        foreach ((array) config('game.hider_deck.powerups', []) as $powerup) {
            for ($i = 0; $i < (int) ($powerup['count'] ?? 1); $i++) {
                $pool[] = ['type' => 'powerup', 'power' => $powerup['power']];
            }
        }

        return $pool;
    }

    /** Draw `n` cards from the pool, each tagged with a unique instance id. */
    private function drawCards(int $n): array
    {
        $pool = $this->deckPool();
        if ($pool === [] || $n <= 0) {
            return [];
        }

        $cards = [];
        for ($i = 0; $i < $n; $i++) {
            $cards[] = $pool[array_rand($pool)] + ['uid' => (string) Str::uuid()];
        }

        return $cards;
    }

    /**
     * The answer the hider is about to give (so they can confirm knowingly). Returns
     * only the PRE-COMPUTED truth (radar inline at ask, OSM via the queued job,
     * thermometer at stop). Never evaluates inline — this runs on the /state read path
     * and must not make a blocking Overpass call. Null = "computing…" / photo upload.
     */
    public function previewAnswer(Session $session): ?array
    {
        return $session->state_data['pending_question']['truth'] ?? null;
    }

    /** Is this seeker physically close enough to the hider's committed spot to catch them? */
    public function seekerCanCatch(Session $session, Player $player): bool
    {
        if ($player->role !== 'seeker' || ! in_array($session->state, ['seeking', 'endgame'], true)) {
            return false;
        }
        $hiderPoint = $this->hiderPoint($session);
        if ($hiderPoint === null || $player->last_lat === null || $player->last_lng === null) {
            return false;
        }
        $radius = (float) ($session->config['endgame_catch_radius_m'] ?? 75);

        return Geo::distanceMeters((float) $player->last_lat, (float) $player->last_lng, $hiderPoint[0], $hiderPoint[1]) <= $radius;
    }

    /** End the round: bank the hider's survival time and record the reveal summary. */
    private function endRound(Session $session, array $data, ?string $finderId, bool $surrendered): ActionOutcome
    {
        $hiderId = $data['hider_id'] ?? null;
        $seconds = max(0, now()->timestamp - (int) ($data['hiding_started_at'] ?? now()->timestamp));
        $bonus = $this->bankedTimeBonusSeconds($data); // kept time-bonus cards add to the hider's time
        if ($hiderId !== null) {
            $data['scores'][$hiderId] = ($data['scores'][$hiderId] ?? 0) + $seconds + $bonus;
        }
        $data['last_round_seconds'] = $seconds;
        $data['last_round'] = $this->roundSummary($session, $data, $finderId, $surrendered, $seconds);

        $event = $surrendered
            ? $this->event('HiderFound', ['round' => $data['round'] ?? 0, 'surrendered' => true])
            : $this->event('HiderFound', ['round' => $data['round'] ?? 0, 'found_by' => $finderId]);

        return new ActionOutcome($data, 'round_end', [$event]);
    }

    /** The reveal/recap shown on the round-end screen (the hider's spot is now safe to show). */
    private function roundSummary(Session $session, array $data, ?string $finderId, bool $surrendered, int $seconds): array
    {
        $hider = ($id = $data['hider_id'] ?? null) ? $session->players->firstWhere('id', $id) : null;
        $finder = $finderId ? $session->players->firstWhere('id', $finderId) : null;

        return [
            'hider_id' => $data['hider_id'] ?? null,
            'hider_name' => $hider?->display_name,
            'found_by' => $finderId,
            'found_by_name' => $finder?->display_name,
            'surrendered' => $surrendered,
            'seconds' => $seconds,
            'hider_position' => $data['hider_position'] ?? null, // the committed spot, now revealed
            'time_bonus_s' => $this->bankedTimeBonusSeconds($data),
            'questions_count' => count($data['questions'] ?? []),
            'curses_played' => count($data['curses_played'] ?? []),
        ];
    }

    /** Seconds added by the time-bonus cards the hider kept in hand. */
    private function bankedTimeBonusSeconds(array $data): int
    {
        return array_sum(array_map(
            fn ($c) => ($c['type'] ?? 'curse') === 'time_bonus' ? max(0, (int) ($c['minutes'] ?? 0)) * 60 : 0,
            $data['hand'] ?? [],
        ));
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
        // Clear all round-scoped state so the next round starts clean (scores persist).
        unset(
            $data['hider_id'], $data['hiding_started_at'], $data['hiding_deadline'], $data['seeking_started_at'], $data['hand'],
            $data['questions'], $data['curses_played'], $data['hider_position'], $data['relocating'], $data['endgame_dwell'],
            $data['pending_question'], $data['question_answer'], $data['thermometer'], $data['last_round'], $data['bonus_draws'],
        );

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
