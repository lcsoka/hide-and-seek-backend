<?php

namespace App\Game\Modes\HideAndSeek;

use App\Enums\GameSize;
use App\Enums\QuestionCategory;
use App\Exceptions\QuestionTruthNotReady;
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
use App\Models\Card;
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
            // Seekers may keep asking questions during the endgame (default on; a host can turn it off).
            'endgame_questions' => true,
            // Opt-in: a question category can't be re-asked until this many seconds after it was
            // last answered (0 = off). `question_cooldowns` overrides it per category.
            'question_cooldown_s' => 0,
            'question_cooldowns' => [], // e.g. ['radar' => 300, 'matching' => 1800]
            'question_answer_time_s' => 600, // hider's window to answer a question (10 min)
            'amend_window_s' => 120, // after answering, the hider can fix a manual answer this long

            'hiding_zone_radius_m' => $size->hidingZoneRadiusMeters(),
            'hiding_zone_rule' => config('game.hiding_zone.default_rule', 'circle'),
            // Which transit stops players may hide at (and that bound the hiding zone).
            'transit_modes' => config('game.hiding_zone.default_modes', ['metro', 'tram']),
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
        // A stale pending question (its window elapsed without resolving) no longer counts as pending,
        // so the seeker can ask again — asking voids the stuck one. See isPendingStale().
        $pending = ($session->state_data['pending_question'] ?? null) !== null && ! $this->isPendingStale($session->state_data ?? []);

        $actions = match ($session->state) {
            'lobby' => $player->is_host ? ['start'] : [],
            'role_assignment' => $player->is_host ? ['assign_hider'] : [],
            'hiding' => $player->role === 'hider' ? ['choose_station', 'confirm_hidden'] : [],
            'seeking' => match ($player->role) {
                // A seeker can't ask while a question is awaiting the hider's answer. They
                // can clear an active curse that demands photo proof, and — once they've
                // physically closed in — catch the hider.
                'seeker' => array_merge(
                    $this->seekerActions($session, $player, $pending),
                    // Catching is a handshake: a seeker who has closed in CLAIMS the catch; it
                    // only ends the round once the hider confirms (so neither side ends it alone).
                    $this->seekerCanCatch($session, $player) && ($session->state_data['found_claim'] ?? null) === null ? ['claim_found'] : [],
                ),
                // The hider is locked to their spot once seeking begins. They answer pending
                // questions and play cards — unless a 'move' powerup put them in relocating
                // mode, in which case they re-confirm their new spot.
                'hider' => array_merge(
                    $pending ? ['answer_question', 'play_curse', 'play_powerup'] : ['play_curse', 'play_powerup'],
                    ($session->state_data['pending_discard'] ?? null) !== null ? ['discard_cards'] : [],
                    ($session->state_data['pending_draw'] ?? null) !== null ? ['keep_cards'] : [],
                    ($session->state_data['pending_curse_choice'] ?? null) !== null ? ['choose_disabled_categories'] : [],
                    ($session->state_data['relocating'] ?? false) ? ['choose_station', 'confirm_hidden'] : [],
                    $this->amendableIndex($session) !== null ? ['amend_answer'] : [],
                    ($session->state_data['found_claim'] ?? null) !== null ? ['confirm_caught', 'dispute_found'] : [],
                    ($session->state_data['hand'] ?? []) !== [] ? ['discard_card'] : [],
                ),
                default => [],
            },
            'endgame' => match ($player->role) {
                // Endgame keeps the seeker's full toolkit (ask/thermometer/transit) when questions are
                // enabled (default), plus the catch. 'declare_endgame' is dropped — already in endgame.
                'seeker' => array_merge(
                    ($session->config['endgame_questions'] ?? true)
                        ? array_values(array_diff($this->seekerActions($session, $player, $pending), ['declare_endgame']))
                        : [],
                    // In the endgame the catch is a MANUAL claim (the hider confirms), so it works
                    // even if the seeker's GPS is stale/unavailable — otherwise a location glitch can
                    // leave the game unendable (a real playtest lost a win to a stuck seeker position).
                    ($session->state_data['found_claim'] ?? null) === null ? ['claim_found'] : [],
                ),
                'hider' => array_merge(
                    ['surrender'],
                    ($session->state_data['found_claim'] ?? null) !== null ? ['confirm_caught', 'dispute_found'] : [],
                ),
                default => [],
            },
            'round_end' => $player->is_host ? ['advance_round'] : [],
            default => [],
        };

        // The host can stop the game at any point (e.g. to avoid leaving it abandoned).
        if ($player->is_host && $session->state !== 'finished') {
            $actions[] = 'end_game';
        }

        // Safety net: void a stuck pending question so play returns to normal (the seeker can ask
        // again, the hider is freed). Available to the host, or to the seeker who asked it — so a
        // question that jams the game (e.g. its resolve never fired) can always be cleared.
        $pendingQ = $session->state_data['pending_question'] ?? null;
        if ($pendingQ !== null && ($player->is_host || ($pendingQ['asked_by'] ?? null) === $player->id)) {
            $actions[] = 'cancel_question';
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
            // In the endgame a seeker may claim the catch manually (the hider confirms), so a stale
            // GPS can't block the win. During seeking the proximity gate still applies.
            'claim_found' => (($player->role === 'seeker' && $session->state === 'endgame') || $this->seekerCanCatch($session, $player))
                ? ValidationResult::pass()
                : ValidationResult::fail('You are not close enough to the hider to catch them.'),
            'confirm_caught', 'dispute_found' => ($session->state_data['found_claim'] ?? null) !== null
                ? ValidationResult::pass()
                : ValidationResult::fail('No catch has been claimed.'),
            'amend_answer' => ($this->amendableIndex($session) !== null && ($action->payload['answer'] ?? null) !== null)
                ? ValidationResult::pass()
                : ValidationResult::fail('There is no recent answer to change.'),
            'cancel_question' => ($session->state_data['pending_question'] ?? null) !== null
                ? ValidationResult::pass()
                : ValidationResult::fail('There is no pending question to cancel.'),
            'ask_question' => $this->validateAsk($session, $this->questionCategoryOf($action)),
            'start_thermometer' => isset(($session->state_data['on_transit'] ?? [])[$player->id])
                ? ValidationResult::fail('Get off transit before starting a thermometer — it must be walked.')
                : $this->validateAsk($session, QuestionCategory::Thermometer->value),
            'board_transit' => $this->validateBoardTransit($session, $player),
            'alight_transit' => isset(($session->state_data['on_transit'] ?? [])[$player->id])
                ? ValidationResult::pass()
                : ValidationResult::fail('You are not on transit.'),
            'answer_question' => ($session->state_data['pending_question'] ?? null) !== null
                ? ValidationResult::pass()
                : ValidationResult::fail('There is no question to answer.'),
            'choose_station' => (isset($action->payload['lat'], $action->payload['lng']))
                ? ValidationResult::pass()
                : ValidationResult::fail('choose_station requires the station lat and lng.'),
            'confirm_hidden' => $this->validateWithinHidingZone($session, $player),
            'choose_disabled_categories' => $this->validateChooseDisabled($session, $action),
            'play_curse' => $this->validatePlayCurse($session, $action),
            'hangman_guess' => (isset($action->payload['curse_uid'], $action->payload['letter']))
                ? ValidationResult::pass()
                : ValidationResult::fail('hangman_guess requires a curse_uid and a letter.'),
            default => ValidationResult::pass(),
        };
    }

    /** Some curses need the hider to attach something when casting: a photo, or a hangman word. */
    private function validatePlayCurse(Session $session, Action $action): ValidationResult
    {
        $uid = $action->payload['card_uid'] ?? null;
        $handCard = collect($session->state_data['hand'] ?? [])->firstWhere('uid', $uid);
        $card = isset($handCard['curse_id']) ? Card::find($handCard['curse_id']) : null;
        if ($card === null) {
            return ValidationResult::pass();
        }
        $effect = $card->effect ?? [];

        if ((! empty($effect['hider_photo']) || ! empty($effect['hider_video'])) && empty($action->payload['photo_url'])) {
            return ValidationResult::fail('This curse needs a photo or video to send to the seekers.');
        }
        if (! empty($effect['hangman']) && ! Hangman::isValid((string) ($action->payload['word'] ?? ''))) {
            return ValidationResult::fail('This curse needs a word ('.Hangman::MIN_LENGTH.'–'.Hangman::MAX_LENGTH.' letters) for the seekers to guess.');
        }

        return ValidationResult::pass();
    }

    /** A seeker may board public transport only while walking (no thermometer running) and not already aboard. */
    private function validateBoardTransit(Session $session, Player $player): ValidationResult
    {
        if (($session->state_data['thermometer'] ?? null) !== null) {
            return ValidationResult::fail('You cannot board transit during a thermometer — it must be walked.');
        }
        if (isset(($session->state_data['on_transit'] ?? [])[$player->id])) {
            return ValidationResult::fail('You are already on transit.');
        }

        if ($player->last_lat === null || $player->last_lng === null) {
            return ValidationResult::fail('Report your location before boarding.');
        }
        // Boarding stamps the journey log with where you got on; a wifi-tower fix would file it
        // blocks away from the actual stop.
        if (! $player->hasReliableFix()) {
            return ValidationResult::fail('Your GPS signal is too weak to board — wait for a better fix.');
        }

        return ValidationResult::pass();
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
            'board_transit' => $this->boardTransit($player, $action, $data),
            'alight_transit' => $this->alightTransit($player, $action, $data),
            'answer_question' => $this->answerQuestion($session, $action, $data),
            'cancel_question' => $this->cancelQuestion($data),
            'play_curse' => $this->playCurse($player, $action, $data),
            'play_powerup' => $this->playPowerup($action, $data),
            'keep_cards' => $this->keepCards($action, $data),
            'discard_cards' => $this->discardCards($action, $data),
            'discard_card' => $this->discardCard($action, $data),
            'choose_disabled_categories' => $this->chooseDisabledCategories($action, $data),
            'amend_answer' => $this->amendAnswer($session, $action, $data),
            'complete_curse' => $this->completeCurse($player, $action, $data),
            'roll_dice' => $this->rollDice($action, $data),
            'hangman_guess' => $this->hangmanGuess($action, $data),
            'declare_endgame' => new ActionOutcome($data, 'endgame', [$this->event('EndgameTriggered', ['by' => $player->id])]),
            'claim_found' => $this->claimFound($player, $data),
            'confirm_caught' => $this->endRound($session, $this->withoutFoundClaim($data), $data['found_claim']['by'] ?? null, surrendered: false),
            'dispute_found' => new ActionOutcome($this->withoutFoundClaim($data), null, [$this->event('FoundDisputed', ['by' => $player->id])]),
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

    /**
     * Deadlines that have already elapsed but whose queued timer never fired — surfaced so a
     * read/action resolves them lazily (a pending question clears itself when the hider's
     * window ends; a stuck hiding phase advances) instead of the game hanging on a dead queue.
     *
     * @return array<string, int|null>
     */
    public function overdueTimers(Session $session): array
    {
        $data = $session->state_data ?? [];
        $now = now()->timestamp;
        $due = [];

        $deadline = $data['pending_question']['deadline'] ?? null;
        if ($deadline !== null && $deadline < $now) {
            $due['question_answer'] = $data['question_answer'] ?? null;
        }

        if ($session->state === 'hiding' && ($data['hiding_deadline'] ?? null) !== null && $data['hiding_deadline'] < $now) {
            $due['hiding_deadline'] = null;
        }

        return $due;
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

        if ($viewer->role === 'hider' || $viewer->id === $hiderId) {
            // Faithful to the real game: the hider does NOT see the seekers closing in — their
            // tension is not knowing how near the hunt is. A casual game can opt back in.
            return ($session->config['reveal_seekers_to_hider'] ?? false)
                ? LocationFilter::only($allIds)
                : LocationFilter::only([$viewer->id]);
        }

        // Seekers see everyone except the hider.
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
        // The hider's starting spot. During seeking this point tracks the hider live within their
        // zone (see updateHiderSpot); the endgame then locks it wherever they stand.
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

    /** A seeker who has closed in claims the catch; the round ends only once the hider confirms. */
    private function claimFound(Player $seeker, array $data): ActionOutcome
    {
        $data['found_claim'] = ['by' => $seeker->id, 'at' => now()->timestamp];

        return new ActionOutcome($data, null, [$this->event('FoundClaimed', ['by' => $seeker->id])]);
    }

    /** Drop a pending catch claim (the hider confirmed or disputed it). */
    private function withoutFoundClaim(array $data): array
    {
        unset($data['found_claim']);

        return $data;
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
            if ($p->role !== 'seeker' || ! $p->hasReliableFix()) {
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
        if ($session->state !== 'seeking') {
            return null;
        }
        // The hider roams freely inside their zone during the run — their committed spot tracks
        // them until the endgame locks it (see updateHiderSpot).
        if ($player->role === 'hider') {
            return $this->updateHiderSpot($session, $player);
        }
        if ($player->role !== 'seeker') {
            return null;
        }
        $zone = $session->state_data['hiding_zone'] ?? null;
        $center = $zone['center'] ?? null;
        // A poor fix neither starts nor cancels the dwell clock: it could place a seeker who is
        // still streets away inside the zone, or bounce one who is standing in it back out and
        // reset their timer.
        if ($center === null || ! $player->hasReliableFix()) {
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
     * While seeking, the hider may move anywhere inside their zone; their committed spot (the point
     * questions are answered against AND the point seekers must reach to catch them) tracks their live
     * position. Only in-zone fixes count — a fix outside the zone (GPS drift / rule-break) is ignored so
     * the spot can't be dragged out — and the spot is frozen while a question awaits an answer, so each
     * answer stays consistent with where the hider was when it was asked. The endgame stops all updates
     * (onLocationReported returns early unless state is 'seeking'), locking the spot where they stand.
     */
    private function updateHiderSpot(Session $session, Player $player): ?ActionOutcome
    {
        // This point is the ground truth every question is judged against, so it only ever moves
        // on a fix we trust — otherwise a single bad reading would drag the hider across their
        // zone and quietly invalidate every cut drawn from that moment on. Keeping the last good
        // spot is strictly safer: the hider is somewhere near it either way.
        if (! $player->hasReliableFix()) {
            return null;
        }
        if (($session->state_data['pending_question'] ?? null) !== null) {
            return null;
        }
        $zone = $session->state_data['hiding_zone'] ?? null;
        $center = $zone['center'] ?? null;
        if ($center === null) {
            return null;
        }
        if (Geo::distanceMeters((float) $player->last_lat, (float) $player->last_lng, (float) $center['lat'], (float) $center['lng']) > (float) ($zone['radius_m'] ?? 0)) {
            return null;
        }

        $data = $session->state_data;
        $data['hider_position'] = ['lat' => (float) $player->last_lat, 'lng' => (float) $player->last_lng];

        return new ActionOutcome($data);
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

        // Keep this action fast + side-effect-free: just record the zone (centre/radius/rule).
        // The client carves it for display by fetching nearby transit stops itself via the
        // cached /geo/overpass proxy — so we don't make (and cache) several Overpass calls on
        // the request path, which previously contended on the SQLite cache and could fail.
        $zone = ['center' => ['lat' => $lat, 'lng' => $lng], 'radius_m' => $radius, 'rule' => $rule];

        $data['hiding_zone'] = $zone;

        // Player-scoped: only the hider receives their zone (with coordinates).
        return new ActionOutcome($data, null, [$this->playerEvent('HidingZoneChosen', $zone, $hider->id)]);
    }

    private function validateWithinHidingZone(Session $session, Player $hider): ValidationResult
    {
        // Confirming commits the spot the whole round is then measured from, so it is worth
        // waiting a few seconds for a real fix. Checked ahead of the branches below so it covers
        // both the normal confirm and the post-relocation one.
        if ($hider->last_lat !== null && $hider->last_lng !== null && ! $hider->hasReliableFix()) {
            return ValidationResult::fail('Your GPS signal is too weak to confirm — wait for a better fix.');
        }

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

    /** The category of the question being asked (from its question_id), or null. */
    private function questionCategoryOf(Action $action): ?string
    {
        $id = $action->payload['question_id'] ?? null;

        return $id !== null ? Question::find($id)?->category?->value : null;
    }

    /** A question may be asked only with no pending question, no blocking curse, and an enabled category. */
    /** A pending question whose answer window elapsed >60s ago without resolving — its resolve timer
     *  never fired, so it must not deadlock the game by blocking every future ask. */
    private function isPendingStale(array $data): bool
    {
        $pending = $data['pending_question'] ?? null;

        return $pending !== null && isset($pending['deadline']) && ($pending['deadline'] + 60) < now()->timestamp;
    }

    private function validateAsk(Session $session, ?string $category): ValidationResult
    {
        $data = $session->state_data ?? [];
        // A pending question blocks new ones — UNLESS it's stale (its answer window fully elapsed
        // without resolving, e.g. the resolve timer never fired), which would otherwise deadlock the
        // game. A stale one is voided by askQuestion as it's replaced.
        if (($data['pending_question'] ?? null) !== null && ! $this->isPendingStale($data)) {
            return ValidationResult::fail('A question is already awaiting an answer.');
        }
        if ($this->hasBlockingCurse($data)) {
            return ValidationResult::fail('Clear the active curse before asking a question.');
        }
        if ($category !== null && in_array($category, $this->disabledCategories($data), true)) {
            return ValidationResult::fail('That question category is disabled by a curse.');
        }
        if ($category !== null) {
            $until = (int) (($data['cooldowns'][$category] ?? 0));
            $remaining = $until - now()->timestamp;
            if ($remaining > 0) {
                return ValidationResult::fail("That question type is cooling down — try again in {$remaining}s.");
            }
        }

        return ValidationResult::pass();
    }

    /** Seconds a category is locked after being answered (per-category override, else the default). */
    private function cooldownFor(Session $session, string $category): int
    {
        $overrides = (array) ($session->config['question_cooldowns'] ?? []);

        return max(0, (int) ($overrides[$category] ?? $session->config['question_cooldown_s'] ?? 0));
    }

    /** The hider must choose exactly the curse-required number of (valid) categories to disable. */
    private function validateChooseDisabled(Session $session, Action $action): ValidationResult
    {
        $choice = $session->state_data['pending_curse_choice'] ?? null;
        if ($choice === null) {
            return ValidationResult::fail('No curse is awaiting a category choice.');
        }
        $valid = array_map(fn ($c) => $c->value, QuestionCategory::cases());
        $chosen = array_values(array_unique(array_intersect((array) ($action->payload['categories'] ?? []), $valid)));

        return count($chosen) === (int) ($choice['count'] ?? 3)
            ? ValidationResult::pass()
            : ValidationResult::fail('Choose exactly '.($choice['count'] ?? 3).' question categories.');
    }

    /** The hider picks which categories The Drained Brain disables for the round. */
    private function chooseDisabledCategories(Action $action, array $data): ActionOutcome
    {
        $choice = $data['pending_curse_choice'] ?? null;
        if ($choice === null) {
            return new ActionOutcome($data);
        }
        $valid = array_map(fn ($c) => $c->value, QuestionCategory::cases());
        $chosen = array_slice(array_values(array_unique(array_intersect((array) ($action->payload['categories'] ?? []), $valid))), 0, (int) ($choice['count'] ?? 3));

        $data['disabled_categories'] = array_values(array_unique(array_merge($data['disabled_categories'] ?? [], $chosen)));
        $data['pending_curse_choice'] = null;

        return new ActionOutcome($data, null, [$this->event('CategoriesDisabled', ['categories' => $chosen])]);
    }

    /** The seeker's available actions, accounting for a running thermometer + active curses. */
    private function seekerActions(Session $session, Player $player, bool $pending): array
    {
        $data = $session->state_data ?? [];

        $active = $this->activeCurseInstances($data);
        // A blocking curse locks questions until the seekers clear it.
        $blocked = (bool) array_filter($active, fn ($c) => ! empty($c['blocks_asking']));
        // A hangman curse is cleared by solving the puzzle (hangman_guess), not a plain complete.
        $canComplete = (bool) array_filter($active, fn ($c) => (! empty($c['requires_proof']) || ! empty($c['blocks_asking'])) && empty($c['dice']) && empty($c['hangman']));
        $canGuessHangman = (bool) array_filter($active, fn ($c) => ! empty($c['hangman']));
        $canRoll = (bool) array_filter($active, fn ($c) => ! empty($c['dice']));
        // Clearing an active curse (proof / dice / hangman) is ALWAYS allowed — even mid-thermometer.
        // A curse's task doesn't wait for the walk to finish, so the seeker must be able to respond to
        // it without first stopping the thermometer.
        $clearCurse = array_merge(
            $canComplete ? ['complete_curse'] : [],
            $canGuessHangman ? ['hangman_guess'] : [],
            $canRoll ? ['roll_dice'] : [],
        );

        // Mid-thermometer: the seeker is travelling on foot — they can stop it and still clear curses.
        if (($data['thermometer'] ?? null) !== null) {
            return array_merge(['stop_thermometer', 'declare_endgame'], $clearCurse);
        }

        // Public-transport board/alight (the journey log). The thermometer must be WALKED:
        // a seeker can't start one while on transit, and boarding isn't offered mid-thermometer.
        $onTransit = isset(($data['on_transit'] ?? [])[$player->id]);
        $asks = $onTransit ? ['ask_question'] : ['ask_question', 'start_thermometer'];

        return array_merge(
            $pending || $blocked ? ['declare_endgame'] : array_merge($asks, ['declare_endgame']),
            [$onTransit ? 'alight_transit' : 'board_transit'],
            $clearCurse,
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

    /**
     * A seeker boards public transport — opens a journey leg. The optional payload records the
     * chosen stop + line (stop_name/stop_lat/stop_lng/line/mode); otherwise the seeker's GPS is
     * used. Blocked while a thermometer is running (it must be walked); see validateBoardTransit.
     */
    private function boardTransit(Player $player, Action $action, array $data): ActionOutcome
    {
        $p = $action->payload;
        $data['on_transit'][$player->id] = [
            'lat' => isset($p['stop_lat']) ? (float) $p['stop_lat'] : (float) $player->last_lat,
            'lng' => isset($p['stop_lng']) ? (float) $p['stop_lng'] : (float) $player->last_lng,
            'at' => now()->timestamp,
            'stop' => $p['stop_name'] ?? null,
            'line' => $p['line'] ?? null,
            'mode' => $p['mode'] ?? null,
        ];

        return new ActionOutcome($data, null, [$this->event('TransitBoarded', ['by' => $player->id, 'line' => $p['line'] ?? null])]);
    }

    /** The seeker alights — closes the open leg (keeping its line + stops) and logs it. */
    private function alightTransit(Player $player, Action $action, array $data): ActionOutcome
    {
        $board = $data['on_transit'][$player->id] ?? null;
        unset($data['on_transit'][$player->id]);

        if ($board !== null) {
            $data['transit_log'][] = [
                'player_id' => $player->id,
                'line' => $board['line'] ?? null,
                'mode' => $board['mode'] ?? null,
                'board_stop' => $board['stop'] ?? null,
                'board_lat' => $board['lat'], 'board_lng' => $board['lng'], 'board_at' => $board['at'],
                'alight_stop' => $action->payload['stop_name'] ?? null,
                'alight_lat' => $player->last_lat !== null ? (float) $player->last_lat : null,
                'alight_lng' => $player->last_lng !== null ? (float) $player->last_lng : null,
                'alight_at' => now()->timestamp,
            ];
        }

        return new ActionOutcome($data, null, [$this->event('TransitAlighted', ['by' => $player->id])]);
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
            $payload['admin_level'] = $payload['admin_level'] ?? ($question->parameters['admin_level'] ?? null);
            $payload['boundary_level'] = $payload['boundary_level'] ?? ($question->parameters['boundary_level'] ?? null);
        }

        $window = $question?->answer_time_s ?? (int) ($session->config['question_answer_time_s'] ?? 600);
        $seq = ($data['question_seq'] ?? 0) + 1;

        // Self-heal: if the previous question got stuck (its resolve timer never fired), void it as we
        // replace it, so clients drop the stale "awaiting answer" state instead of deadlocking.
        $voidEvents = $this->isPendingStale($data)
            ? [$this->event('QuestionVoided', ['seq' => $data['pending_question']['seq'] ?? null, 'category' => $data['pending_question']['category'] ?? null])]
            : [];

        // Deferred (thermometer): capture the seeker's start position; resolve later.
        // Overpass-backed categories (matching/measuring/tentacles) compute truth inline so the
        // hider immediately sees their OWN nearest place on the map (and can answer knowingly),
        // even with no queue worker; a queued job is the fallback if Overpass is down right now.
        // Radar is pure geometry → inline.
        $truth = null;
        $jobs = [];
        if ($evaluator instanceof DeferredQuestionEvaluator) {
            $payload['start_lat'] = $asker->last_lat;
            $payload['start_lng'] = $asker->last_lng;
        } elseif ($evaluator !== null) {
            if (in_array($question->category->value, ['matching', 'measuring', 'tentacles'], true)) {
                // Auto-computable when there's OSM geometry to resolve: a point feature, an admin
                // area (same-division matching) or a boundary line (border measuring). Subjects with
                // none of these (sea level, coastline, high-speed rail, transit line, ...) aren't —
                // the hider answers those manually, so skip both compute paths.
                if (($payload['feature'] ?? null) !== null
                    || ($payload['admin_level'] ?? null) !== null
                    || ($payload['boundary_level'] ?? null) !== null) {
                    // Try inline first (fast, cached Overpass); on any failure fall back to the
                    // retrying job so the ask still returns and truth fills in when Overpass recovers.
                    try {
                        $truth = $evaluator->evaluate($session, $asker, $question, $payload);
                    } catch (\Throwable) {
                        $truth = null;
                    }
                    if ($truth === null) {
                        $jobs[] = new ComputeQuestionTruth($session->id, $seq);
                    }
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
            array_merge($voidEvents, [$this->event('QuestionAsked', [
                'seq' => $seq,
                'question_id' => $question?->id,
                'category' => $question?->category->value,
                'asked_by' => $asker->id,
                'deadline' => $data['pending_question']['deadline'],
            ])]),
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
            // Overpass was unavailable / no data — let the queued job retry (backoff). Expected +
            // already logged, so it's excluded from error reporting (see bootstrap/app.php).
            throw new QuestionTruthNotReady("Could not compute truth for question seq {$seq} yet.");
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
        $payload = $action->payload;
        // Photo questions are answered with an uploaded image, not a verdict.
        if (isset($payload['photo_url'])) {
            $manual = ['answer' => 'photo', 'photo_url' => $payload['photo_url']];
        } else {
            $manual = ['answer' => $payload['answer'] ?? null];
            // Tentacles/matching answered by hand: the hider also names the specific place
            // they're nearest to, so the seekers get the real region (a Voronoi cell), not
            // just "in range". Carry the chosen feature through onto the answer.
            if (isset($payload['feature_name'])) {
                $manual['feature_name'] = (string) $payload['feature_name'];
            }
            if (isset($payload['feature_lat'], $payload['feature_lng'])) {
                $manual['feature_lat'] = (float) $payload['feature_lat'];
                $manual['feature_lng'] = (float) $payload['feature_lng'];
            }
        }

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

        // Matching + measuring are SERVER-AUTHORITATIVE (anti-cheat): the map decides, so prefer
        // the computed truth — pre-computed by the job, or inline here if the job never ran (e.g.
        // no queue worker) — over any manual answer. A manual answer for these only counts when
        // Overpass is genuinely unreachable, so the hider can't skew the cut with a wrong guess.
        // Other categories keep the fast manual-first path (radar/thermometer are pure geometry;
        // photo/tentacles need the hider's own input).
        $manual = is_array($hiderAnswer) ? $hiderAnswer : ['answer' => $hiderAnswer];
        $hasManual = ($manual['answer'] ?? null) !== null;
        $authoritative = in_array($pending['category'] ?? null, ['matching', 'measuring'], true);
        // The server-computed answer: the job's pre-computed truth, or (for authoritative
        // categories) an inline compute if the job never ran.
        $computed = $pending['truth'] ?? ($authoritative ? $this->evaluateTruth($session, $pending) : null);
        $answer = $computed
            ?? ($hasManual ? $manual : null)
            ?? (! $authoritative ? $this->evaluateTruth($session, $pending) : null);

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

        // True only when the hider's own input actually set the recorded answer (no server-computed
        // answer available) — only these are amendable, so anti-cheat answers can't be overridden.
        $manualAnswer = $computed === null && $hasManual;

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
        // Start this category's cooldown so the seekers can't immediately re-ask the same type.
        if (($cat = $pending['category'] ?? null) !== null && ($cd = $this->cooldownFor($session, $cat)) > 0) {
            $data['cooldowns'][$cat] = now()->timestamp + $cd;
        }
        $this->rotateSpotty($data); // Spotty Memory changes its disabled category after each question

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
            $drawn = $this->drawFromDeck($data, $drawN);
            if ($auto) {
                // Keep the reward, then trim to the hand limit (drops the oldest — the reward survives).
                $data['hand'] = $this->trimHand(array_merge($data['hand'] ?? [], array_slice($drawn, 0, $keepN)), $data);
            } else {
                // Offer the full reward; the limit is enforced when the hider commits their choice.
                $data['pending_draw'] = ['cards' => $drawn, 'keep' => min($keepN, count($drawn))];
            }
        }

        return new ActionOutcome($data, null, [
            $this->event('QuestionAnswered', [
                'seq' => $pending['seq'],
                'question_id' => $pending['question_id'],
                'category' => $pending['category'] ?? null, // so the seeker toast can show the right icon
                'asked_by' => $pending['asked_by'] ?? null,
                'auto' => $auto,
            ] + $answer),
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

    /**
     * Safety net: void a stuck pending question, returning play to normal (the seeker can ask
     * again, the hider is freed). Triggered by the host or the seeker who asked it — so a question
     * that jams the game (e.g. its resolve timer never fired) can always be cleared without a reset.
     */
    private function cancelQuestion(array $data): ActionOutcome
    {
        $pending = $data['pending_question'] ?? null;
        if ($pending === null) {
            return new ActionOutcome($data);
        }
        $data['pending_question'] = null;
        $data['question_answer'] = null;

        return new ActionOutcome($data, null, [
            $this->event('QuestionVoided', ['seq' => $pending['seq'] ?? null, 'asked_by' => $pending['asked_by'] ?? null]),
        ]);
    }

    /** The hider plays a curse card from their hand (removed by uid) against the seekers. */
    private function playCurse(Player $player, Action $action, array $data): ActionOutcome
    {
        $card = $this->takeFromHand($data, $action->payload['card_uid'] ?? null, 'curse');
        if ($card === null) {
            return new ActionOutcome($data);
        }
        $curseId = $card['curse_id'] ?? null;

        // The curse's structured effect drives every consequence (no hardcoded keys).
        $curse = $curseId !== null ? Card::find($curseId) : null;
        $effect = $curse?->effect ?? [];
        $duration = isset($effect['duration_s']) ? (int) $effect['duration_s'] : null;
        $now = now()->timestamp;
        $uid = (string) Str::uuid();

        $instance = [
            'uid' => $uid,
            'curse_id' => $curseId,
            'by' => $player->id,
            'round' => $data['round'] ?? 0,
            'at' => $now,
            'requires_proof' => (bool) ($effect['requires_proof'] ?? false),
            'blocks_asking' => (bool) ($effect['blocks_asking'] ?? false),
            'dice' => $effect['dice'] ?? null,
            'rolls' => [],
            'expires_at' => $duration !== null ? $now + $duration : null,
            'status' => 'active',
            'proof_url' => null,
            // The hider's own photo/video handed to the seekers (e.g. the Unguided Tourist's Street
            // View screenshot, or the Bird Guide's video) — captured at play time when the curse
            // sets hider_photo or hider_video. The media url arrives as photo_url either way.
            'hint_photo_url' => (! empty($effect['hider_photo']) || ! empty($effect['hider_video'])) ? ($action->payload['photo_url'] ?? null) : null,
            'completed_at' => null,
        ];

        // The Hidden Gallows: the hider sets a word the seekers must solve to lift the asking block
        // (a duration_s cap guarantees an impossible word can't block forever). The word stays
        // server-side; the presenter only ever exposes a masked view.
        if (! empty($effect['hangman'])) {
            $instance['hangman'] = Hangman::newState($action->payload['word'] ?? null);
        }

        // bonus_draws: a hider self-buff (no seeker task) → grant the draws + resolve at once.
        if ($bonus = (int) ($effect['bonus_draws']['count'] ?? 0)) {
            $data['bonus_draws'] = ($data['bonus_draws'] ?? 0) + $bonus;
            $instance['status'] = 'completed';
            $instance['completed_at'] = $now;
        }

        // disable_categories: 'choose' opens a hider picker; 'random' disables one now
        // (rotating each question if configured).
        if (is_array($disable = $effect['disable_categories'] ?? null)) {
            $count = max(1, (int) ($disable['count'] ?? 1));
            if (($disable['mode'] ?? 'random') === 'choose') {
                $data['pending_curse_choice'] = ['uid' => $uid, 'curse_id' => $curseId, 'count' => $count];
            } elseif (! empty($disable['rotates'])) {
                $instance['rotates_category'] = true;
                $data['spotty_category'] = $this->randomCategory($this->disabledCategories($data));
            } else {
                for ($i = 0; $i < $count; $i++) {
                    if ($cat = $this->randomCategory($data['disabled_categories'] ?? [])) {
                        $data['disabled_categories'][] = $cat;
                    }
                }
            }
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

    /**
     * A seeker guesses a letter in the Hidden Gallows word puzzle. A hit reveals the letter (and
     * clears the curse when the word is complete); a miss counts toward the limit, after which the
     * puzzle resets with a fresh word — so a bad run only delays, never dead-ends, the seekers.
     */
    private function hangmanGuess(Action $action, array $data): ActionOutcome
    {
        $uid = $action->payload['curse_uid'] ?? null;
        $letter = Hangman::fold((string) ($action->payload['letter'] ?? ''));
        if ($letter === '' || ! in_array($letter, Hangman::ALPHABET, true)) {
            return new ActionOutcome($data);
        }

        foreach ($data['curses_played'] ?? [] as $i => $curse) {
            if (($curse['uid'] ?? null) !== $uid
                || ($curse['status'] ?? 'active') !== 'active'
                || empty($curse['hangman'])) {
                continue;
            }

            $hm = $curse['hangman'];
            // A letter already tried is a no-op (guarding double-taps / replays).
            if (in_array($letter, $hm['guessed'] ?? [], true) || in_array($letter, $hm['wrong'] ?? [], true)) {
                return new ActionOutcome($data);
            }

            if (Hangman::wordContains($hm['word'], $letter)) {
                $hm['guessed'][] = $letter;
                if (Hangman::isSolved($hm['word'], $hm['guessed'])) {
                    $data['curses_played'][$i]['hangman'] = $hm;
                    $data['curses_played'][$i]['status'] = 'completed';
                    $data['curses_played'][$i]['completed_at'] = now()->timestamp;

                    return new ActionOutcome($data, null, [$this->event('HangmanSolved', ['uid' => $uid, 'word' => $hm['word']])]);
                }
            } else {
                $hm['wrong'][] = $letter;
                // Gallows full → wipe the guesses and start over on the SAME (hider's) word, so
                // deliberately losing is never a quick escape. The duration_s cap is the real
                // safety net against an unsolvable word.
                if (count($hm['wrong']) >= (int) ($hm['max_wrong'] ?? Hangman::MAX_WRONG)) {
                    $hm['guessed'] = [];
                    $hm['wrong'] = [];
                }
            }

            $data['curses_played'][$i]['hangman'] = $hm;

            return new ActionOutcome($data, null, [$this->event('HangmanGuessed', ['uid' => $uid, 'letter' => $letter])]);
        }

        return new ActionOutcome($data);
    }

    /** Active (current-round, unexpired) curse instances. */
    private function activeCurseInstances(array $data): array
    {
        $now = now()->timestamp;
        $round = $data['round'] ?? 0;

        return array_values(array_filter($data['curses_played'] ?? [], fn ($c) => ($c['round'] ?? 0) === $round
            && ($c['status'] ?? 'active') === 'active'
            && (($c['expires_at'] ?? null) === null || $c['expires_at'] > $now)));
    }

    /** True if an active curse blocks the seekers from asking until they clear it. */
    private function hasBlockingCurse(array $data): bool
    {
        return (bool) array_filter($this->activeCurseInstances($data), fn ($c) => ! empty($c['blocks_asking']));
    }

    /** Question categories the seekers currently can't ask (Drained Brain + the rotating Spotty Memory). */
    private function disabledCategories(array $data): array
    {
        $disabled = $data['disabled_categories'] ?? [];
        if (! empty($data['spotty_category'])) {
            $disabled[] = $data['spotty_category'];
        }

        return array_values(array_unique($disabled));
    }

    /** A random question category not in $exclude, or null if all are excluded. */
    private function randomCategory(array $exclude): ?string
    {
        $pool = array_values(array_diff(array_map(fn ($c) => $c->value, QuestionCategory::cases()), $exclude));

        return $pool === [] ? null : $pool[array_rand($pool)];
    }

    /** Spotty Memory "changes after each question": rotate its disabled category to a new one. */
    private function rotateSpotty(array &$data): void
    {
        $rotating = (bool) array_filter($this->activeCurseInstances($data), fn ($c) => ! empty($c['rotates_category']));
        if ($rotating) {
            $exclude = array_merge($data['disabled_categories'] ?? [], [$data['spotty_category'] ?? null]);
            $data['spotty_category'] = $this->randomCategory(array_filter($exclude));
        }
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
            // Discard the whole hand (gone, not returned to the deck) and draw fresh.
            $data['hand'] = $this->drawFromDeck($data, count($data['hand'] ?? []));
        } else {
            // 'draw_1_expand_1' permanently raises the hand limit by 1 (do it before the draw so
            // the new card fits) and reveals the card through the keep-draw modal.
            if ($power === 'draw_1_expand_1') {
                $data['hand_limit'] = $this->handLimit($data) + 1;
                $cards = $this->drawFromDeck($data, 1);
                $data['pending_draw'] = ['cards' => $cards, 'keep' => count($cards)];
            }
            // Cycle powerups (discard N, draw N+1): net-neutral on hand size — the played card PLUS
            // N cards the hider chooses leave, then the replacements are drawn. The hider first
            // picks which cards to shed (pending_discard); discard_cards draws + opens the keep modal.
            $cycle = ['discard_1_draw_2' => ['discard' => 1, 'draw' => 2], 'discard_2_draw_3' => ['discard' => 2, 'draw' => 3]][$power] ?? null;
            if ($cycle !== null) {
                $need = min($cycle['discard'], count($data['hand'] ?? []));
                if ($need > 0) {
                    $data['pending_discard'] = ['need' => $need, 'draw' => $cycle['draw']];
                } else {
                    // Nothing left to shed (the powerup was the hider's last card) — just draw.
                    $cards = $this->drawFromDeck($data, $cycle['draw']);
                    $data['pending_draw'] = ['cards' => $cards, 'keep' => count($cards)];
                }
            }
            // 'move' lets the hider relocate: drop the committed spot AND the old hiding zone
            // (questions fall back to live GPS meanwhile) so the hider picks a fresh station and
            // the zone is recalculated around it before they re-confirm.
            if ($power === 'move') {
                $data['relocating'] = true;
                unset($data['hider_position'], $data['hiding_zone']);
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
        // Honour the reward count, then trim the WHOLE hand to its limit (oldest dropped) — so a full
        // hand no longer means "keep 0"; the freshly-kept reward always survives.
        $kept = array_slice($kept, 0, (int) ($draw['keep'] ?? 0));
        $data['hand'] = $this->trimHand(array_merge($data['hand'] ?? [], $kept), $data);
        $data['pending_draw'] = null;

        return new ActionOutcome($data, null, [$this->event('CardsKept', ['count' => count($kept)])]);
    }

    /**
     * The hider pays a cycle powerup's cost: shed the chosen hand cards, then draw the replacements
     * (revealed through the keep modal). Requires the full cost — a short selection is a no-op, so
     * the pending_discard stays until the hider commits the right number of cards.
     */
    private function discardCards(Action $action, array $data): ActionOutcome
    {
        $pending = $data['pending_discard'] ?? null;
        if ($pending === null) {
            return new ActionOutcome($data);
        }

        $need = (int) ($pending['need'] ?? 0);
        // Only uids actually in the hand count. Validate the FULL cost before shedding anything, so a
        // short/partial selection leaves the hand (and the pending_discard) untouched.
        $handUids = array_column($data['hand'] ?? [], 'uid');
        $valid = array_values(array_intersect(array_unique((array) ($action->payload['uids'] ?? [])), $handUids));
        if (count($valid) < $need) {
            return new ActionOutcome($data); // wait for a full choice — nothing shed yet
        }

        foreach (array_slice($valid, 0, $need) as $uid) {
            $this->takeFromHand($data, $uid);
        }
        $data['pending_discard'] = null;
        $cards = $this->drawFromDeck($data, (int) ($pending['draw'] ?? 0));
        $data['pending_draw'] = ['cards' => $cards, 'keep' => count($cards)];

        return new ActionOutcome($data, null, [$this->event('CardsDiscarded', ['count' => $need])]);
    }

    /** The hider's current max hand size (config default, raised by 'draw_1_expand_1'). */
    private function handLimit(array $data): int
    {
        return (int) ($data['hand_limit'] ?? config('game.hand_limit', 6));
    }

    /** Trim a hand to the current limit, dropping the OLDEST cards (freshly-kept reward is newest). */
    private function trimHand(array $hand, array $data): array
    {
        $limit = $this->handLimit($data);

        return count($hand) > $limit ? array_slice($hand, -$limit) : $hand;
    }

    /** The hider drops a card from their hand to make room (manage the hand limit). */
    private function discardCard(Action $action, array $data): ActionOutcome
    {
        $uid = $action->payload['card_uid'] ?? null;
        foreach ($data['hand'] ?? [] as $i => $card) {
            if (($card['uid'] ?? null) === $uid) {
                array_splice($data['hand'], $i, 1);

                return new ActionOutcome($data, null, [$this->event('CardDiscarded', ['uid' => $uid])]);
            }
        }

        return new ActionOutcome($data);
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

    /**
     * Build the hider draw pool: official cards (curses + time-bonus + powerups) plus the host's
     * own custom curses, so a user's saved curses appear only in the games they host. When the host
     * curated the deck in the new-game wizard, `$enabledIds` limits the pool to the cards they kept.
     *
     * @param  array<int, string>|null  $enabledIds
     */
    private function deckPool(?int $hostUserId = null, ?array $enabledIds = null): array
    {
        $pool = [];
        $cards = Card::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $hostUserId))
            ->when(! empty($enabledIds), fn ($q) => $q->whereIn('id', $enabledIds))
            ->orderBy('sort')->get();
        foreach ($cards as $card) {
            $descriptor = match ($card->type) {
                'powerup' => ['type' => 'powerup', 'power' => $card->power],
                'time_bonus' => ['type' => 'time_bonus', 'minutes' => $card->minutes],
                default => ['type' => 'curse', 'curse_id' => $card->id],
            };
            for ($i = 0, $n = max(1, (int) $card->count); $i < $n; $i++) {
                $pool[] = $descriptor;
            }
        }

        return $pool;
    }

    /**
     * Draw `n` cards off the top of the game deck, each tagged with a unique instance id.
     * The deck is a shuffled, depleting pile built once and persisted in state_data: it is
     * NOT refilled, so a card drawn (or played) in an earlier round can never be drawn again
     * — unless the deck holds multiple copies of it. Runs out → fewer/no cards (never errors).
     */
    private function drawFromDeck(array &$data, int $n): array
    {
        if (! isset($data['deck'])) {
            $deck = $this->deckPool(
                isset($data['host_user_id']) ? (int) $data['host_user_id'] : null,
                isset($data['deck_cards']) && is_array($data['deck_cards']) ? $data['deck_cards'] : null,
            );
            shuffle($deck);
            $data['deck'] = $deck;
        }

        $cards = [];
        for ($i = 0; $i < $n && ! empty($data['deck']); $i++) {
            $cards[] = array_shift($data['deck']) + ['uid' => (string) Str::uuid()];
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
        // The catch radius is 75 m — smaller than the error on a wifi/cell fix, so an untrusted
        // reading could hand a seeker the catch from the far side of the neighbourhood. In the
        // endgame the claim stays available regardless (see availableActions), so this only
        // gates the silent proximity catch.
        if ($hiderPoint === null || ! $player->hasReliableFix()) {
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
        $bonus = $this->bankedTimeBonusSeconds($data, (string) ($session->config['game_size'] ?? 'medium')); // kept time-bonus cards add to the hider's time
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
            'time_bonus_s' => $this->bankedTimeBonusSeconds($data, (string) ($session->config['game_size'] ?? 'medium')),
            'questions_count' => count($data['questions'] ?? []),
            'curses_played' => count($data['curses_played'] ?? []),
        ];
    }

    /** Seconds added by the time-bonus cards the hider kept in hand. */
    private function bankedTimeBonusSeconds(array $data, string $size): int
    {
        return array_sum(array_map(
            fn ($c) => ($c['type'] ?? 'curse') === 'time_bonus' ? max(0, Card::minutesForSize($c['minutes'] ?? 0, $size)) * 60 : 0,
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

        // Archive the round that just ended before it's cleared, so a full-game replay can show every
        // round's questions and curses (state_data only ever holds the current round).
        $data['rounds_log'] = array_merge($data['rounds_log'] ?? [], [[
            'round' => $data['round'] ?? 0,
            'questions' => $data['questions'] ?? [],
            'curses_played' => $data['curses_played'] ?? [],
        ]]);

        $session->players()->update(['role' => null]);
        $data['round'] = $completed;
        // Clear all round-scoped state so the next round starts clean (scores persist).
        unset(
            $data['hider_id'], $data['hiding_started_at'], $data['hiding_deadline'], $data['seeking_started_at'], $data['hand'],
            $data['questions'], $data['question_seq'], $data['curses_played'], $data['hider_position'], $data['relocating'], $data['endgame_dwell'],
            $data['pending_question'], $data['question_answer'], $data['thermometer'], $data['last_round'], $data['bonus_draws'],
            $data['disabled_categories'], $data['spotty_category'], $data['pending_curse_choice'], $data['hand_limit'], $data['hiding_zone'], $data['cooldowns'],
            $data['on_transit'], $data['transit_log'], $data['found_claim'],
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
