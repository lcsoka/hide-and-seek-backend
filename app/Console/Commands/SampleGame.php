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
use App\Models\PlayerPosition;
use App\Models\Question;
use App\Models\Session;
use App\Models\User;
use Database\Seeders\CardSeeder;
use Database\Seeders\QuestionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Generates ONE fully-featured, persisted sample game for exploring the admin (state editor +
 * replay): multiple rounds, seekers that MOVE (recorded position tracks), every question category,
 * curses played, and a transit ride. Unlike game:simulate this persists and records movement.
 *
 * Usage: php artisan game:sample [--rounds=2] [--seekers=2]
 */
class SampleGame extends Command
{
    protected $signature = 'game:sample {--rounds=2} {--seekers=2}';

    protected $description = 'Create one rich, persisted sample game (movement, all questions, curses, transit) for the admin.';

    private GameEngine $engine;

    private Carbon $clock;

    /** @var string[] */
    private array $cats = ['radar', 'matching', 'measuring', 'tentacles', 'photo'];

    private int $catIx = 0;

    public function handle(GameEngine $engine, SessionFactory $factory): int
    {
        $this->engine = $engine;
        $this->clock = now()->subMinutes(28);

        // Offline OSM features so matching/measuring/tentacles resolve without Overpass.
        app()->instance(MapDataSource::class, new ArrayMapDataSource([
            new GeoFeature('m1', 'museum', 47.498, 19.040, 'Museum A'),
            new GeoFeature('m2', 'museum', 47.520, 19.080, 'Museum B'),
            new GeoFeature('r1', 'rail_station', 47.500, 19.050, 'Station A'),
            new GeoFeature('r2', 'rail_station', 47.510, 19.070, 'Station B'),
            new GeoFeature('p1', 'park', 47.505, 19.045, 'Park A'),
        ]));

        if (! Card::query()->exists()) {
            $this->callSilent('db:seed', ['--class' => CardSeeder::class]);
        }
        if (! Question::query()->exists()) {
            $this->callSilent('db:seed', ['--class' => QuestionSeeder::class]);
        }

        $host = User::factory()->create(['name' => 'Sample Host']);
        $session = $factory->create($host, config('game.default_mode'), 'budapest', GameSize::Medium, ['rounds' => (int) $this->option('rounds')]);
        for ($i = 1; $i <= max(1, (int) $this->option('seekers')); $i++) {
            $factory->join($session, User::factory()->create(['name' => "Sample Seeker {$i}"]), "Seeker {$i}");
        }

        $guard = 0;
        while ($session->state !== 'finished' && $guard++ < 400) {
            $session = match ($session->state) {
                'lobby' => $this->act($session, $this->host($session), 'start'),
                'role_assignment' => $this->act($session, $this->host($session), 'assign_hider', ['player_id' => $this->host($session)->id]),
                'hiding' => $this->hide($session),
                'seeking' => $this->seek($session),
                'endgame' => $this->endgame($session),
                'round_end' => $this->act($session, $this->host($session), 'advance_round'),
                default => $session,
            };
        }

        $base = rtrim((string) config('app.url', 'http://hide-and-seek.test'), '/');
        $this->newLine();
        $this->info('✅ Sample game created and persisted.');
        $this->line("  Join code:   <options=bold>{$session->join_code}</>");
        $this->line("  Session id:  {$session->id}");
        $this->line('  Positions:   '.PlayerPosition::where('session_id', $session->id)->count().' recorded');
        $this->line('  Questions:   '.count($session->state_data['questions'] ?? []).' · curses: '.count($session->state_data['curses_played'] ?? []).' · transit legs: '.count($session->state_data['transit_log'] ?? []));
        $this->newLine();
        $this->line("  State editor: {$base}/admin/sessions/{$session->id}/edit");
        $this->line("  Replay:       {$base}/admin/sessions/{$session->id}/replay");

        return self::SUCCESS;
    }

    private function hide(Session $session): Session
    {
        $hider = $this->hider($session);
        $this->moveTo($session, $hider, 47.495 + mt_rand(0, 120) / 10000, 19.035 + mt_rand(0, 120) / 10000, 3);
        $session = $this->act($session, $hider, 'choose_station', ['lat' => (float) $hider->last_lat, 'lng' => (float) $hider->last_lng]);

        return $this->act($session, $this->hider($session), 'confirm_hidden');
    }

    private function seek(Session $session): Session
    {
        $session = $this->ensureCurseInHand($session);
        $steps = 7; // enough to cover every question category (+ a thermometer) and several curses
        for ($i = 0; $i < $steps && $session->state === 'seeking'; $i++) {
            $session = $this->clearCurses($session);
            $seeker = $this->aSeeker($session);
            // Move the seeker to a fresh spot (recording a track).
            $this->moveTo($session, $seeker, 47.50 + mt_rand(-60, 60) / 1000, 19.05 + mt_rand(-60, 60) / 1000, 4);

            // One transit ride on the first step.
            if ($i === 0) {
                $session = $this->ride($session, $seeker);
                $seeker = $this->seekerById($session, $seeker->id);
            }

            // A thermometer every so often; otherwise cycle through the question catalogue.
            if ($i === 1 && $this->canAct($session, $seeker, 'start_thermometer')) {
                $q = $this->randomQuestion('thermometer');
                $session = $this->act($session, $seeker, 'start_thermometer', $this->askPayload($q) + ['distance_m' => 800, 'distance_label' => '½ mile']);
                $this->moveTo($session, $this->seekerById($session, $seeker->id), 47.52, 19.08, 3);
                $session = $this->act($session, $this->seekerById($session, $seeker->id), 'stop_thermometer');
            } elseif ($this->canAct($session, $seeker, 'ask_question')) {
                $disabled = array_merge($session->state_data['disabled_categories'] ?? [], array_filter([$session->state_data['spotty_category'] ?? null]));
                $cat = $this->nextCategory($disabled);
                if ($cat !== null && ($q = $this->randomQuestion($cat)) !== null) {
                    $session = $this->act($session, $seeker, 'ask_question', $this->askPayload($q));
                }
            }

            // The hider plays a curse (when they have one), then answers.
            $session = $this->playACurse($session);
            if (($session->state_data['pending_question'] ?? null) !== null) {
                $cat = $session->state_data['pending_question']['category'] ?? null;
                $payload = $cat === 'photo' ? ['photo_url' => 'https://placehold.co/320x200/e11d48/white?text=Photo'] : ['answer' => $this->manualAnswer($cat)];
                $session = $this->act($session, $this->hider($session), 'answer_question', $payload);
                $session = $this->resolveDraw($session);
            }
        }

        return $session->state === 'seeking' ? $this->act($session, $this->aSeeker($session), 'declare_endgame') : $session;
    }

    /** A seeker boards a line, rides (recording a track), and alights at another stop. */
    private function ride(Session $session, Player $seeker): Session
    {
        if (! $this->canAct($session, $seeker, 'board_transit')) {
            return $session;
        }
        $this->moveTo($session, $seeker, 47.500, 19.050, 2);
        $session = $this->act($session, $this->seekerById($session, $seeker->id), 'board_transit', [
            'stop_name' => 'Station A', 'stop_lat' => 47.500, 'stop_lng' => 19.050, 'line' => 'M2', 'mode' => 'metro',
        ]);
        $this->moveTo($session, $this->seekerById($session, $seeker->id), 47.510, 19.070, 5);
        if ($this->canAct($session, $this->seekerById($session, $seeker->id), 'alight_transit')) {
            $session = $this->act($session, $this->seekerById($session, $seeker->id), 'alight_transit', [
                'stop_name' => 'Station B', 'lat' => 47.510, 'lng' => 19.070,
            ]);
        }

        return $session;
    }

    private function endgame(Session $session): Session
    {
        $point = $session->state_data['hider_position'] ?? null;
        if ($point !== null) {
            $seeker = $this->aSeeker($session);
            $this->moveTo($session, $seeker, (float) $point['lat'], (float) $point['lng'], 4);
            if ($this->canAct($session, $this->seekerById($session, $seeker->id), 'claim_found')) {
                $session = $this->act($session, $this->seekerById($session, $seeker->id), 'claim_found');

                return $this->act($session, $this->hider($session), 'confirm_caught');
            }
        }

        return $this->act($session, $this->hider($session), 'surrender');
    }

    // --- movement --------------------------------------------------------

    private function moveTo(Session $session, Player $player, float $lat, float $lng, int $waypoints): void
    {
        $fromLat = (float) ($player->last_lat ?? $lat);
        $fromLng = (float) ($player->last_lng ?? $lng);
        for ($i = 1; $i <= $waypoints; $i++) {
            $f = $i / $waypoints;
            $this->clock = $this->clock->copy()->addSeconds(mt_rand(18, 40));
            PlayerPosition::create([
                'session_id' => $session->id,
                'player_id' => $player->id,
                'lat' => $fromLat + ($lat - $fromLat) * $f + mt_rand(-6, 6) / 100000,
                'lng' => $fromLng + ($lng - $fromLng) * $f + mt_rand(-6, 6) / 100000,
                'recorded_at' => $this->clock,
            ]);
        }
        $player->update(['last_lat' => $lat, 'last_lng' => $lng, 'last_location_at' => $this->clock]);
    }

    // --- card helpers (adapted from game:simulate) -----------------------

    /** Guarantee the hider holds a curse (that doesn't disable question categories) so one gets played. */
    private function ensureCurseInHand(Session $session): Session
    {
        $sd = $session->state_data;
        $hand = $sd['hand'] ?? [];
        if (collect($hand)->contains(fn ($c) => ($c['type'] ?? null) === 'curse')) {
            return $session;
        }
        $curse = Card::query()->where('type', 'curse')->where('is_active', true)->get()
            ->first(fn ($c) => ! isset(($c->effect ?? [])['disable_categories']));
        if ($curse === null) {
            return $session;
        }
        $hand[] = ['uid' => (string) Str::uuid(), 'type' => 'curse', 'curse_id' => $curse->id];
        $sd['hand'] = $hand;
        $session->update(['state_data' => $sd]);

        return $session->refresh();
    }

    private function playACurse(Session $session): Session
    {
        $hider = $this->hider($session);
        $curse = collect($session->state_data['hand'] ?? [])->firstWhere('type', 'curse');
        $card = $curse ?? collect($session->state_data['hand'] ?? [])->shuffle()->first();
        if ($card === null) {
            return $session;
        }
        if (($card['type'] ?? null) === 'curse') {
            $session = $this->act($session, $hider, 'play_curse', ['card_uid' => $card['uid']]);

            return $this->resolveCurseChoice($session);
        }
        if (($card['type'] ?? null) === 'powerup') {
            $session = $this->act($session, $hider, 'play_powerup', ['card_uid' => $card['uid']]);
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

        return $this->act($session, $this->hider($session), 'keep_cards', ['uids' => $keep]);
    }

    private function resolveCurseChoice(Session $session): Session
    {
        $choice = $session->state_data['pending_curse_choice'] ?? null;
        if ($choice === null) {
            return $session;
        }
        $cats = array_slice(['radar', 'matching', 'measuring', 'thermometer'], 0, (int) $choice['count']);

        return $this->act($session, $this->hider($session), 'choose_disabled_categories', ['categories' => $cats]);
    }

    private function resolveRelocate(Session $session): Session
    {
        if (! ($session->state_data['relocating'] ?? false)) {
            return $session;
        }
        $hider = $this->hider($session);
        $this->moveTo($session, $hider, 47.50, 19.06, 2);

        return $this->act($session, $this->hider($session), 'confirm_hidden');
    }

    private function clearCurses(Session $session): Session
    {
        foreach ($session->state_data['curses_played'] ?? [] as $curse) {
            if (($curse['status'] ?? 'active') !== 'active') {
                continue;
            }
            $seeker = $this->aSeeker($session);
            if (! empty($curse['dice']) && $this->canAct($session, $seeker, 'roll_dice')) {
                $session = $this->act($session, $seeker, 'roll_dice', ['curse_uid' => $curse['uid']]);
            } elseif ($this->canAct($session, $seeker, 'complete_curse')) {
                $session = $this->act($session, $seeker, 'complete_curse', ['curse_uid' => $curse['uid'], 'proof_url' => 'https://placehold.co/320x200/7c3aed/white?text=Proof']);
            }
        }

        return $session;
    }

    // --- low-level -------------------------------------------------------

    private function act(Session $session, Player $player, string $type, array $payload = []): Session
    {
        try {
            return $this->engine->submit($session->refresh(), $player->refresh(), new Action($type, $payload));
        } catch (ValidationException) {
            return $session->refresh();
        } catch (\Throwable $e) {
            $this->warn("  action {$type} failed: ".$e->getMessage());

            return $session->refresh();
        }
    }

    private function canAct(Session $session, Player $player, string $type): bool
    {
        return in_array($type, $this->engine->modeFor($session)->availableActions($session, $player), true);
    }

    private function nextCategory(array $disabled): ?string
    {
        for ($n = 0; $n < count($this->cats); $n++) {
            $cat = $this->cats[$this->catIx++ % count($this->cats)];
            if (! in_array($cat, $disabled, true)) {
                return $cat;
            }
        }

        return null;
    }

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
            'measuring' => collect(['closer', 'further'])->random(),
            'tentacles' => collect(['in_range', 'out_of_range'])->random(),
            default => collect(['yes', 'no'])->random(),
        };
    }
}
