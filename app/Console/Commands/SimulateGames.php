<?php

namespace App\Console\Commands;

use App\Enums\GameSize;
use App\Game\GameEngine;
use App\Game\Geo\ArrayMapDataSource;
use App\Game\Geo\GeoFeature;
use App\Game\Geo\MapDataSource;
use App\Game\SessionFactory;
use App\Game\Support\Action;
use App\Models\Card;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;
use App\Models\User;
use Database\Seeders\CardSeeder;
use Database\Seeders\QuestionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soak-tests the gameplay: plays full hide-and-seek games headlessly through the engine,
 * randomizing questions/cards/answers, and reports any exception or broken invariant
 * (bad state, hand over its limit, negative score). Everything runs inside a rolled-back
 * transaction, so it never pollutes the database.
 *
 * Usage: php artisan game:simulate --games=25 --rounds=2 --seekers=2 [--seed=123]
 */
class SimulateGames extends Command
{
    protected $signature = 'game:simulate {--games=10} {--rounds=2} {--seekers=2} {--seed=} {--size=small} {--watch} {--delay=3}';

    protected $description = 'Play automated test games and report gameplay anomalies (use --watch to spectate one live).';

    /** @var array<int, string> */
    private array $anomalies = [];

    private GameEngine $engine;

    private SessionFactory $factory;

    /** Watch mode: persist + broadcast a single game, paced so you can spectate it live. */
    private bool $watch = false;

    private int $delay = 3;

    public function handle(GameEngine $engine, SessionFactory $factory): int
    {
        $this->engine = $engine;
        $this->factory = $factory;

        if (($seed = $this->option('seed')) !== null) {
            mt_srand((int) $seed);
        }

        // Local map data so OSM-backed questions resolve without hitting Overpass.
        app()->instance(MapDataSource::class, new ArrayMapDataSource([
            new GeoFeature('m1', 'museum', 47.498, 19.040, 'Museum A'),
            new GeoFeature('m2', 'museum', 47.520, 19.080, 'Museum B'),
            new GeoFeature('r1', 'rail_station', 47.500, 19.050, 'Station A'),
            new GeoFeature('r2', 'rail_station', 47.510, 19.070, 'Station B'),
            new GeoFeature('p1', 'park', 47.505, 19.045, 'Park A'),
        ]));

        $this->callSilent('db:seed', ['--class' => CardSeeder::class]);
        $this->callSilent('db:seed', ['--class' => QuestionSeeder::class]);

        $this->watch = (bool) $this->option('watch');
        $this->delay = max(0, (int) $this->option('delay'));
        // Watch mode persists + broadcasts a single game so you can spectate it; the soak
        // mode rolls every game back so it never pollutes the database.
        $games = $this->watch ? 1 : (int) $this->option('games');
        $completed = 0;

        for ($g = 1; $g <= $games; $g++) {
            if ($this->watch) {
                try {
                    if ($this->playGame($g)) {
                        $completed++;
                    }
                } catch (\Throwable $e) {
                    $this->anomalies[] = "game {$g}: UNCAUGHT ".get_class($e).' — '.$e->getMessage();
                }

                continue;
            }

            DB::beginTransaction();
            try {
                if ($this->playGame($g)) {
                    $completed++;
                }
            } catch (\Throwable $e) {
                $this->anomalies[] = "game {$g}: UNCAUGHT ".get_class($e).' — '.$e->getMessage();
            } finally {
                DB::rollBack(); // never persist simulated games
            }
        }

        $this->newLine();
        $this->info("Completed {$completed}/{$games} games cleanly.");
        if ($this->anomalies !== []) {
            $this->error(count($this->anomalies).' anomaly(ies):');
            foreach (array_slice($this->anomalies, 0, 40) as $a) {
                $this->line("  • {$a}");
            }

            return self::FAILURE;
        }

        $this->info('No gameplay anomalies found. 🎉');

        return self::SUCCESS;
    }

    /** Drive one full game through every phase; returns true if it reached `finished`. */
    private function playGame(int $g): bool
    {
        $size = GameSize::tryFrom((string) $this->option('size')) ?? GameSize::Small;
        $seekerCount = max(1, (int) $this->option('seekers'));

        $host = User::factory()->create();
        $session = $this->factory->create($host, config('game.default_mode'), 'budapest', $size, ['rounds' => (int) $this->option('rounds')]);
        for ($i = 0; $i < $seekerCount; $i++) {
            $this->factory->join($session, User::factory()->create(), "Seeker {$i}");
        }

        if ($this->watch) {
            $this->announceWatch($session);
        }

        $guard = 0;
        while ($session->state !== 'finished' && $guard++ < 500) {
            $session = match ($session->state) {
                'lobby' => $this->hostAct($session, 'start'),
                'role_assignment' => $this->startRound($session),
                'hiding' => $this->hide($session),
                'seeking' => $this->seekUntilCaught($session, $g),
                'endgame' => $this->endgame($session),
                'round_end' => $this->hostAct($session, 'advance_round'),
                default => $this->flag($g, "stuck in state {$session->state}") ?? $session,
            };
            $this->checkInvariants($session, $g);
        }

        if ($session->state !== 'finished') {
            $this->flag($g, 'did not finish within the step budget');

            return false;
        }

        return true;
    }

    private function startRound(Session $session): Session
    {
        $host = $this->host($session);

        return $this->submit($session, $host, 'assign_hider', ['player_id' => $host->id]);
    }

    private function hide(Session $session): Session
    {
        $hider = $this->hider($session);
        // Hide on a station and commit there.
        $lat = 47.49 + mt_rand(0, 200) / 10000;
        $lng = 19.03 + mt_rand(0, 200) / 10000;
        $hider->update(['last_lat' => $lat, 'last_lng' => $lng]);
        $session = $this->submit($session, $hider, 'choose_station', ['lat' => $lat, 'lng' => $lng]);

        return $this->submit($session, $this->hider($session), 'confirm_hidden');
    }

    private function seekUntilCaught(Session $session, int $g): Session
    {
        $steps = mt_rand(2, 6);
        for ($i = 0; $i < $steps && $session->state === 'seeking'; $i++) {
            $session = $this->seekStep($session);
        }
        if ($session->state === 'seeking') {
            $session = $this->submit($session, $this->aSeeker($session), 'declare_endgame');
        }

        return $session;
    }

    /** One seeking interaction: a seeker asks (or thermometers), the hider answers + maybe plays a card. */
    private function seekStep(Session $session): Session
    {
        $seeker = $this->aSeeker($session);
        $seeker->update(['last_lat' => 47.50 + mt_rand(-50, 50) / 1000, 'last_lng' => 19.05 + mt_rand(-50, 50) / 1000]);

        // Clear any active curse first (so questions aren't blocked).
        $session = $this->clearCurses($session);

        if (mt_rand(0, 4) === 0 && $this->canAct($session, $seeker, 'start_thermometer')) {
            $session = $this->submit($session, $seeker, 'start_thermometer', $this->askPayload($this->randomQuestion('thermometer')) + ['distance_m' => 800, 'distance_label' => '½ mile']);
            $seeker->update(['last_lat' => 47.52, 'last_lng' => 19.08]);
            $session = $this->submit($session, $this->seekerById($session, $seeker->id), 'stop_thermometer');
        } elseif ($this->canAct($session, $seeker, 'ask_question')) {
            // Don't ask a category a curse has disabled — the engine would (correctly) refuse it.
            $disabled = array_merge($session->state_data['disabled_categories'] ?? [], array_filter([$session->state_data['spotty_category'] ?? null]));
            $allowed = array_values(array_diff(['radar', 'matching', 'measuring', 'tentacles', 'photo'], $disabled));
            if ($allowed === []) {
                return $session;
            }
            $q = $this->randomQuestion(collect($allowed)->random());
            if ($q === null) {
                return $session;
            }
            $session = $this->submit($session, $seeker, 'ask_question', $this->askPayload($q));
        } else {
            return $session;
        }

        // Hider answers (+ sometimes plays a card beforehand).
        $hider = $this->hider($session);
        if (mt_rand(0, 1) === 1) {
            $session = $this->maybePlayCard($session);
            $hider = $this->hider($session);
        }
        if (($session->state_data['pending_question'] ?? null) !== null) {
            $cat = $session->state_data['pending_question']['category'] ?? null;
            $payload = $cat === 'photo' ? ['photo_url' => 'http://example.test/p.jpg'] : ['answer' => $this->manualAnswer($cat)];
            $session = $this->submit($session, $hider, 'answer_question', $payload);
            $session = $this->resolveDraw($session);
        }

        return $session;
    }

    private function endgame(Session $session): Session
    {
        $hiderPoint = $session->state_data['hider_position'] ?? null;
        if ($hiderPoint !== null) {
            // Walk a seeker onto the hider; they claim the catch and the hider confirms it.
            $seeker = $this->aSeeker($session);
            $seeker->update(['last_lat' => $hiderPoint['lat'], 'last_lng' => $hiderPoint['lng']]);
            if ($this->canAct($session, $this->seekerById($session, $seeker->id), 'claim_found')) {
                $session = $this->submit($session, $this->seekerById($session, $seeker->id), 'claim_found');

                return $this->submit($session, $this->hider($session), 'confirm_caught');
            }
        }

        return $this->submit($session, $this->hider($session), 'surrender');
    }

    // --- card helpers ----------------------------------------------------

    private function maybePlayCard(Session $session): Session
    {
        $hider = $this->hider($session);
        $hand = $session->state_data['hand'] ?? [];
        $card = collect($hand)->shuffle()->first();
        if ($card === null) {
            return $session;
        }
        if (($card['type'] ?? null) === 'curse') {
            $session = $this->submit($session, $hider, 'play_curse', ['card_uid' => $card['uid']]);

            return $this->resolveCurseChoice($session);
        }
        if (($card['type'] ?? null) === 'powerup') {
            $extra = ($card['power'] ?? null) === 'duplicate'
                ? ['target_uid' => collect($hand)->firstWhere('uid', '!=', $card['uid'])['uid'] ?? $card['uid']]
                : [];
            $session = $this->submit($session, $hider, 'play_powerup', ['card_uid' => $card['uid']] + $extra);
            $session = $this->resolveDraw($session);

            return $this->resolveRelocate($session);
        }

        return $session;
    }

    private function resolveDraw(Session $session): Session
    {
        $draw = $session->state_data['pending_draw'] ?? null;
        if ($draw === null) {
            return $session;
        }
        $keep = array_slice(array_map(fn ($c) => $c['uid'], $draw['cards'] ?? []), 0, (int) ($draw['keep'] ?? 0));

        return $this->submit($session, $this->hider($session), 'keep_cards', ['uids' => $keep]);
    }

    private function resolveCurseChoice(Session $session): Session
    {
        $choice = $session->state_data['pending_curse_choice'] ?? null;
        if ($choice === null) {
            return $session;
        }
        $cats = array_slice(['radar', 'matching', 'measuring', 'thermometer', 'photo', 'tentacles'], 0, (int) $choice['count']);

        return $this->submit($session, $this->hider($session), 'choose_disabled_categories', ['categories' => $cats]);
    }

    private function resolveRelocate(Session $session): Session
    {
        if (! ($session->state_data['relocating'] ?? false)) {
            return $session;
        }
        $hider = $this->hider($session);
        $hider->update(['last_lat' => 47.50, 'last_lng' => 19.06]);

        return $this->submit($session, $this->hider($session), 'confirm_hidden');
    }

    private function clearCurses(Session $session): Session
    {
        foreach ($session->state_data['curses_played'] ?? [] as $curse) {
            if (($curse['status'] ?? 'active') !== 'active') {
                continue;
            }
            $seeker = $this->aSeeker($session);
            if (! empty($curse['dice']) && $this->canAct($session, $seeker, 'roll_dice')) {
                $session = $this->submit($session, $seeker, 'roll_dice', ['curse_uid' => $curse['uid']]);
            } elseif ($this->canAct($session, $seeker, 'complete_curse')) {
                $session = $this->submit($session, $seeker, 'complete_curse', ['curse_uid' => $curse['uid'], 'proof_url' => 'http://example.test/p.jpg']);
            }
        }

        return $session;
    }

    // --- low-level -------------------------------------------------------

    /** Print the spectate link + give the user a head start to open it before play begins. */
    private function announceWatch(Session $session): void
    {
        $web = rtrim((string) config('app.web_url', 'http://localhost:4321'), '/');
        $this->newLine();
        $this->info('▶ Watch this game live (host + seekers playing automatically):');
        $this->line("  <options=bold>{$web}/dev/duo?watch={$session->join_code}</>");
        $this->line("  (or open {$web}/dev/duo and \"Watch existing\" with code {$session->join_code})");
        $this->comment('  Needs `php artisan reverb:start` + `queue:work` running for live updates.');
        $this->line("  Starting in 10s — open the link now…  (pace: {$this->delay}s/step)");
        $this->newLine();
        sleep(10);
    }

    private function submit(Session $session, Player $player, string $type, array $payload = []): Session
    {
        if ($this->watch) {
            $this->line("  ▸ {$player->display_name}: {$type}");
            sleep($this->delay);
        }

        try {
            return $this->engine->submit($session->refresh(), $player->refresh(), new Action($type, $payload));
        } catch (ValidationException) {
            // The engine correctly refused an illegal action — that's the rules working, not
            // a bug. (A wrongly-refused VALID action would instead show up as a stuck game.)
            return $session->refresh();
        } catch (\Throwable $e) {
            $this->anomalies[] = "action {$type}: ".get_class($e).' — '.$e->getMessage();

            return $session->refresh();
        }
    }

    private function canAct(Session $session, Player $player, string $type): bool
    {
        return in_array($type, $this->engine->modeFor($session)->availableActions($session, $player), true);
    }

    private function checkInvariants(Session $session, int $g): void
    {
        $sd = $session->state_data ?? [];
        $valid = ['lobby', 'role_assignment', 'hiding', 'seeking', 'endgame', 'round_end', 'finished'];
        if (! in_array($session->state, $valid, true)) {
            $this->flag($g, "invalid state {$session->state}");
        }
        $limit = (int) ($sd['hand_limit'] ?? config('game.hand_limit', 6));
        if (count($sd['hand'] ?? []) > $limit) {
            $this->flag($g, 'hand '.count($sd['hand']).' exceeds limit '.$limit);
        }
        foreach ($sd['scores'] ?? [] as $pid => $secs) {
            if ($secs < 0) {
                $this->flag($g, "negative score for {$pid}");
            }
        }
    }

    private function flag(int $g, string $msg): mixed
    {
        $this->anomalies[] = "game {$g}: {$msg}";

        return null;
    }

    // --- lookups + payloads ---------------------------------------------

    private function host(Session $session): Player
    {
        return $session->players()->where('is_host', true)->firstOrFail();
    }

    private function hider(Session $session): Player
    {
        return $session->players()->where('role', 'hider')->firstOrFail();
    }

    private function aSeeker(Session $session): Player
    {
        return $session->players()->where('role', 'seeker')->inRandomOrder()->firstOrFail();
    }

    private function seekerById(Session $session, string $id): Player
    {
        return $session->players()->whereKey($id)->firstOrFail();
    }

    private function hostAct(Session $session, string $type): Session
    {
        return $this->submit($session, $this->host($session), $type);
    }

    private function randomQuestion(string $category): ?Question
    {
        return Question::where('category', $category)->inRandomOrder()->first();
    }

    /** @return array<string, mixed> */
    private function askPayload(?Question $q): array
    {
        if ($q === null) {
            return [];
        }
        $payload = ['question_id' => $q->id];
        if ($q->category->value === 'radar') {
            $payload['radius_m'] = collect([1000, 3000, 5000])->random();
        }
        if ($q->category->value === 'matching') {
            $payload += ['ref_lat' => 47.498, 'ref_lng' => 19.040, 'ref_name' => 'Museum A'];
        }

        return $payload;
    }

    private function manualAnswer(?string $category): string
    {
        return match ($category) {
            'matching' => collect(['yes', 'no'])->random(),
            'measuring' => collect(['closer', 'further'])->random(),
            'tentacles' => collect(['in_range', 'out_of_range'])->random(),
            default => collect(['yes', 'no'])->random(),
        };
    }
}
