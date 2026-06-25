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
                // A seeker can't ask while a question is awaiting the hider's answer. They
                // can clear an active curse that demands photo proof.
                'seeker' => array_merge(
                    $pending ? ['declare_endgame'] : ['ask_question', 'declare_endgame'],
                    $this->curseAwaitingProof($session->state_data ?? []) ? ['complete_curse'] : [],
                ),
                // The hider is locked to their spot once seeking begins (they could only move
                // during the hiding period). They answer pending questions and play cards.
                'hider' => array_merge(
                    $pending ? ['answer_question', 'play_curse', 'play_powerup'] : ['play_curse', 'play_powerup'],
                    ($session->state_data['pending_draw'] ?? null) !== null ? ['keep_cards'] : [],
                ),
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
            'play_curse' => $this->playCurse($player, $action, $data),
            'play_powerup' => $this->playPowerup($action, $data),
            'keep_cards' => $this->keepCards($action, $data),
            'complete_curse' => $this->completeCurse($player, $action, $data),
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

        // The hider starts empty-handed; cards are drawn as questions are answered.
        $data['hand'] = [];

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

    /** The hider may re-hide (move to a new station) only while no seeker is inside their zone. */
    public function canRehide(Session $session): bool
    {
        $zone = $session->state_data['hiding_zone'] ?? null;

        return $session->state === 'seeking' && $zone !== null && ! $this->seekerInZone($session, $zone);
    }

    /** Is any seeker currently within the hider's zone? (Locks re-hiding once they close in.) */
    public function seekerInZone(Session $session, array $zone): bool
    {
        $center = $zone['center'] ?? null;
        if ($center === null) {
            return false;
        }
        $radius = (float) ($zone['radius_m'] ?? 0);

        foreach ($session->players as $p) {
            if ($p->role === 'seeker' && $p->last_lat !== null && $p->last_lng !== null
                && Geo::distanceMeters((float) $p->last_lat, (float) $p->last_lng, (float) $center['lat'], (float) $center['lng']) <= $radius) {
                return true;
            }
        }

        return false;
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
        // Photo questions are answered with an uploaded image, not a verdict.
        $manual = isset($action->payload['photo_url'])
            ? ['answer' => 'photo', 'photo_url' => $action->payload['photo_url']]
            : ['answer' => $action->payload['answer'] ?? null];

        return $this->resolveQuestion($session, $data, $manual, auto: false);
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
        $manual = is_array($hiderAnswer) ? $hiderAnswer : ['answer' => $hiderAnswer];
        $answer = $pending['truth']
            ?? $this->evaluateTruth($session, $pending)
            ?? $this->deferredAnswer($session, $pending)
            ?? $manual;

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

        // Answering rewards the hider: draw `reward_draw` cards and keep `reward_keep`.
        // The hider chooses which to keep (a draw modal); auto-resolve keeps the first few.
        $question = isset($pending['question_id']) ? Question::find($pending['question_id']) : null;
        $drawN = max(0, (int) ($question?->reward_draw ?? 1));
        $keepN = max(0, (int) ($question?->reward_keep ?? 1));
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
        $params = $curseId !== null ? (Curse::find($curseId)?->parameters ?? []) : [];
        $duration = isset($params['duration_s']) ? (int) $params['duration_s'] : null;
        $now = now()->timestamp;

        $data['curses_played'][] = [
            'uid' => (string) Str::uuid(),
            'curse_id' => $curseId,
            'by' => $player->id,
            'round' => $data['round'] ?? 0,
            'at' => $now,
            'requires_proof' => (bool) ($params['requires_proof'] ?? false),
            'expires_at' => $duration !== null ? $now + $duration : null,
            'status' => 'active',
            'proof_url' => null,
            'completed_at' => null,
        ];

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

        if ($power === 'veto' && ($data['pending_question'] ?? null) !== null) {
            // Refuse the question: discard it with no answer and no draw.
            $data['pending_question'] = null;
            $data['question_answer'] = null;
        } elseif ($power === 'duplicate') {
            foreach ($data['hand'] ?? [] as $other) {
                if (($other['uid'] ?? null) === ($action->payload['target_uid'] ?? null)) {
                    $data['hand'][] = ['uid' => (string) Str::uuid()] + array_diff_key($other, ['uid' => true]);
                    break;
                }
            }
        } elseif ($power === 'discard') {
            $data['hand'] = array_merge($data['hand'] ?? [], $this->drawCards(1));
        } elseif ($power === 'randomize') {
            $data['hand'] = $this->drawCards(count($data['hand'] ?? []));
        }
        // 'move' (relocate) is a manual, real-world action — recorded via the event.

        return new ActionOutcome($data, null, [$this->event('PowerupPlayed', ['power' => $power])]);
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
     * The answer the hider is about to give (so they can confirm knowingly). Null for
     * photo questions (they upload) or until an async/deferred truth is available.
     */
    public function previewAnswer(Session $session): ?array
    {
        $pending = $session->state_data['pending_question'] ?? null;
        if ($pending === null) {
            return null;
        }

        return $pending['truth']
            ?? $this->evaluateTruth($session, $pending)
            ?? $this->deferredAnswer($session, $pending);
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
        unset($data['hider_id'], $data['hiding_started_at'], $data['hiding_deadline'], $data['seeking_started_at'], $data['hand']);

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
