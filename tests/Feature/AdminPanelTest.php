<?php

namespace Tests\Feature;

use App\Enums\GameMode;
use App\Enums\SessionStatus;
use App\Filament\Resources\Sessions\Pages\EditSession;
use App\Filament\Resources\Sessions\SessionResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Widgets\GameStatsOverview;
use App\Game\ReplayBuilder;
use App\Models\PlayerPosition;
use App\Filament\Widgets\Leaderboard;
use App\Filament\Widgets\RecentSessions;
use App\Filament\Widgets\SessionsChart;
use App\Filament\Widgets\UserStatsOverview;
use App\Models\Card;
use App\Models\Feedback;
use App\Models\GameResult;
use App\Models\Question;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    /** A user allowed into the admin panel (email added to the allowlist). */
    private function adminUser(): User
    {
        $user = User::factory()->create();
        config(['game.admin_emails' => [strtolower((string) $user->email)]]);

        return $user;
    }

    private function seedSession(): Session
    {
        $session = Session::create([
            'join_code' => 'TEST01',
            'game_mode' => 'hide_and_seek',
            'state' => 'lobby',
            'status' => 'open',
            // city is stored as a {key,name,lat,lng} object — the list's City column must handle that.
            'config' => ['rounds' => 3, 'endgame_radius_m' => 500, 'city' => ['key' => 'budapest', 'name' => 'Budapest', 'lat' => 47.5, 'lng' => 19.0]],
            'state_data' => [],
        ]);

        $team = $session->teams()->create(['name' => 'Reds', 'color' => '#ff0000']);
        $host = $session->players()->create([
            'display_name' => 'Al', 'is_host' => true, 'role' => 'seeker',
            'team_id' => $team->id, 'last_lat' => 47.4979, 'last_lng' => 19.0402,
            'last_location_at' => now(),
        ]);
        $session->players()->create(['display_name' => 'Bo', 'role' => 'hider']);
        $session->update(['host_player_id' => $host->id]);
        $session->actionLogs()->create([
            'player_id' => $host->id, 'type' => 'session_created', 'payload' => ['note' => 'test'],
        ]);

        return $session;
    }

    public function test_resource_index_pages_load(): void
    {
        $this->seedSession();
        $this->actingAs($this->adminUser());

        foreach (['sessions', 'players', 'teams', 'action-logs'] as $slug) {
            $this->get("/admin/{$slug}")->assertSuccessful();
        }
    }

    public function test_session_edit_page_with_relation_managers_loads(): void
    {
        $session = $this->seedSession();
        $this->actingAs($this->adminUser());

        // The edit page renders the form (enum selects, JSON editors) and the
        // players/teams/action-logs relation managers.
        $this->get("/admin/sessions/{$session->getKey()}/edit")->assertSuccessful();
    }

    public function test_enums_round_trip_on_the_model(): void
    {
        $session = $this->seedSession()->fresh();

        $this->assertSame(SessionStatus::Open, $session->status);
        $this->assertSame(GameMode::HideAndSeek, $session->game_mode);
        $this->assertIsArray($session->config);
    }

    public function test_content_and_feedback_admin_pages_load(): void
    {
        $this->seed(); // questions + curses + sample session
        $this->actingAs($this->adminUser());

        foreach (['questions', 'cards', 'feedback', 'deck'] as $slug) {
            $this->get("/admin/{$slug}")->assertSuccessful();
        }

        // Edit/triage pages render their forms (a curse, a powerup, and a time-bonus card).
        $question = Question::query()->first();
        $this->get("/admin/questions/{$question->getKey()}/edit")->assertSuccessful();

        foreach (['curse', 'powerup', 'time_bonus'] as $type) {
            $card = Card::where('type', $type)->firstOrFail();
            $this->get("/admin/cards/{$card->getKey()}/edit")->assertSuccessful();
        }

        $feedback = Feedback::create([
            'type' => 'bug', 'message' => 'something broke', 'status' => 'open',
        ]);
        $this->get("/admin/feedback/{$feedback->getKey()}/edit")->assertSuccessful();
    }

    public function test_non_allowlisted_users_cannot_reach_the_panel(): void
    {
        config(['game.admin_emails' => ['admin@example.com']]);

        // A guest (no email) and a logged-in non-admin are both denied.
        $this->actingAs(User::factory()->create(['email' => null]));
        $this->get('/admin')->assertForbidden();

        $this->actingAs(User::factory()->create(['email' => 'someone@else.test']));
        $this->get('/admin')->assertForbidden();
    }

    public function test_dashboard_renders_the_stats_widgets(): void
    {
        $this->seedSession();
        $this->actingAs($this->adminUser());

        // The dashboard page builds without error (widgets register).
        $this->get('/admin')->assertSuccessful();

        // The widgets render their content (queries don't throw).
        Livewire::test(GameStatsOverview::class)
            ->assertOk()
            ->assertSee('Live games')
            ->assertSee('Questions asked')
            ->assertSee('Open feedback');
        Livewire::test(SessionsChart::class)->assertOk();
        Livewire::test(RecentSessions::class)->assertOk()->assertSee('TEST01');
    }

    public function test_users_resource_list_and_view_pages_load(): void
    {
        $player = User::factory()->create(['name' => 'Registered Rita']);
        GameResult::create([
            'user_id' => $player->id, 'display_name' => 'Rita',
            'hide_time_s' => 125, 'won' => true, 'players_count' => 3, 'played_at' => now(),
        ]);
        $this->actingAs($this->adminUser());

        $this->get('/admin/users')->assertSuccessful()->assertSee('Registered Rita');
        // The view page renders the infolist + the four relation managers (history/sessions/UGC).
        $this->get('/admin/users/'.$player->getKey())->assertSuccessful();
    }

    public function test_is_admin_flag_grants_panel_access(): void
    {
        config(['game.admin_emails' => ['nobody@example.com']]);

        // Registered but not admin and not allowlisted → denied.
        $plain = User::factory()->create();
        $this->actingAs($plain)->get('/admin')->assertForbidden();

        // Granting is_admin lets them in, even though they aren't in the env allowlist.
        $plain->forceFill(['is_admin' => true])->save();
        $this->actingAs($plain->fresh())->get('/admin')->assertSuccessful();

        // A guest (no email) can never reach the panel, even if the flag is set.
        $guest = User::factory()->create(['email' => null]);
        $guest->forceFill(['is_admin' => true])->save();
        $this->actingAs($guest->fresh())->get('/admin')->assertForbidden();
    }

    public function test_stats_resource_and_widgets_load(): void
    {
        $player = User::factory()->create();
        GameResult::create([
            'user_id' => $player->id, 'display_name' => 'Rita',
            'hide_time_s' => 305, 'won' => true, 'players_count' => 4, 'played_at' => now(),
        ]);
        $this->seedSession();
        $this->actingAs($this->adminUser());

        $this->get('/admin/game-results')->assertSuccessful()->assertSee('Rita');

        Livewire::test(UserStatsOverview::class)->assertOk()->assertSee('Registered users');
        Livewire::test(Leaderboard::class)->assertOk()->assertSee('Leaderboard');
    }

    public function test_custom_content_shows_author_and_can_be_moderated(): void
    {
        $author = User::factory()->create(['name' => 'Maker Mia']);
        $question = Question::create([
            'key' => 'custom.mod-test',
            'category' => 'photo',
            'title' => ['en' => 'My question', 'hu' => 'Kérdésem'],
            'prompt' => ['en' => 'Send a pic', 'hu' => 'Küldj képet'],
            'reward_draw' => 1, 'reward_keep' => 1, 'answer_time_s' => 300,
            'parameters' => [], 'is_custom' => true, 'is_active' => true, 'sort' => 999,
            'user_id' => $author->id,
        ]);
        $this->actingAs($this->adminUser());

        $this->get('/admin/questions')->assertSuccessful()->assertSee('Maker Mia');
        $this->assertSame('1', \App\Filament\Resources\Questions\QuestionResource::getNavigationBadge());

        // Deactivate the player-made question from the table.
        Livewire::test(\App\Filament\Resources\Questions\Pages\ListQuestions::class)
            ->callTableAction('toggleActive', $question);
        $this->assertFalse($question->fresh()->is_active);
    }

    public function test_view_modal_renders_the_state_trees(): void
    {
        $session = $this->seedSession();
        $session->update(['config' => ['units' => 'metric'], 'state_data' => ['round' => 1]]);
        $this->actingAs($this->adminUser());

        // The (read-only) View action mounts the same visual-tree form without error.
        \Livewire\Livewire::test(\App\Filament\Resources\Sessions\Pages\ListSessions::class)
            ->mountTableAction('view', $session->getKey())
            ->assertOk();
    }

    public function test_edit_form_shows_json_trees_and_saves_nested_edits(): void
    {
        $session = $this->seedSession();
        $hider = $session->players()->where('role', 'hider')->first();
        $seeker = $session->players()->where('role', 'seeker')->first();
        $session->update([
            'config' => ['units' => 'metric', 'reveal_seekers_to_hider' => false, 'transit_modes' => ['metro', 'tram']],
            'state_data' => ['round' => 2, 'hider_id' => $hider->id, 'seeking_started_at' => 1782998047,
                'hiding_zone' => ['center' => ['lat' => 47.5, 'lng' => 19.05], 'radius_m' => 500, 'rule' => 'nearest'],
                'hand' => [
                    ['uid' => 'h1', 'type' => 'curse', 'name' => 'Tentacles', 'cost' => '2'],
                    // a time_bonus card whose minutes is an object — this used to crash the pill.
                    ['uid' => 'h2', 'type' => 'time_bonus', 'minutes' => ['small' => 5, 'medium' => 10, 'large' => 20]],
                ],
                'questions' => [['seq' => 1, 'category' => 'radar', 'asked_by' => $seeker->id, 'answer' => ['answer' => 'yes']]],
                'scores' => [$hider->id => 320],
                'proof_url' => 'https://example.com/proof.jpg',
                'color' => '#e11d48'],
        ]);
        $this->actingAs($this->adminUser());

        // The edit page renders: units segment ('imperial'), transit chips ('rail'), the hider_id
        // resolved to a player card (the hider's name), the timestamp formatted (year), and a
        // location map for the hiding zone (its center coordinates as data attributes).
        $this->get(SessionResource::getUrl('edit', ['record' => $session]))
            ->assertSuccessful()
            ->assertSee('Game state')
            ->assertSee('Config')
            ->assertSee('imperial')
            ->assertSee('rail')
            ->assertSee($hider->display_name)
            ->assertSee('2026')
            ->assertSee('jt-map', false)
            ->assertSee('data-lat="47.5"', false)
            ->assertSee('Tentacles')      // hand card rendered as a summary pill
            ->assertSee('Radar')          // question card rendered as a summary pill
            ->assertSee('5/10/20 min')    // time_bonus card with object minutes (no crash)
            ->assertSee('5:20')           // scores value formatted as a duration
            ->assertSee('jt-thumb', false)   // proof_url rendered as an image thumbnail
            ->assertSee('jt-swatch', false); // color rendered as a swatch

        // Edit nested values through the tree (bound to the form state), then save.
        Livewire::test(EditSession::class, ['record' => $session->getKey()])
            ->set('data.config.units', 'imperial')
            ->set('data.state_data.round', 5)
            ->set('data.state_data.hiding_zone.radius_m', 750)
            ->call('save');

        $session->refresh();
        $this->assertSame('imperial', $session->config['units']);
        $this->assertSame(5, $session->state_data['round']);
        $this->assertSame(750, $session->state_data['hiding_zone']['radius_m']);
    }

    public function test_replay_builder_and_page(): void
    {
        \Illuminate\Support\Facades\Http::fake(['nominatim.openstreetmap.org/*' => \Illuminate\Support\Facades\Http::response([
            ['geojson' => ['type' => 'Polygon', 'coordinates' => [[[19.0, 47.4], [19.1, 47.4], [19.1, 47.6], [19.0, 47.6], [19.0, 47.4]]]]],
        ])]);
        // Offline transit stops so zone carving is deterministic (no real Overpass call from the builder).
        app()->instance(\App\Game\Geo\MapDataSource::class, new \App\Game\Geo\ArrayMapDataSource([
            new \App\Game\Geo\GeoFeature('s1', 'tram_stop', 47.50, 19.05, 'Chosen stop'),
            new \App\Game\Geo\GeoFeature('s2', 'tram_stop', 47.505, 19.058, 'Neighbour'),
        ]));
        $session = $this->seedSession();
        $hider = $session->players()->where('role', 'hider')->first();

        PlayerPosition::create(['session_id' => $session->id, 'player_id' => $hider->id, 'lat' => 47.50, 'lng' => 19.05, 'recorded_at' => now()->subSeconds(10)]);
        PlayerPosition::create(['session_id' => $session->id, 'player_id' => $hider->id, 'lat' => 47.51, 'lng' => 19.06, 'recorded_at' => now()->subSeconds(4)]);

        $session->update(['state_data' => [
            'round' => 1,
            'hiding_zone' => ['center' => [47.5, 19.05], 'radius_m' => 500, 'rule' => 'nearest'],
            // The live (final) round's questions…
            'questions' => [[
                'seq' => 2, 'category' => 'radar', 'asked_by' => $hider->id,
                'asked_at' => now()->subSeconds(8)->timestamp, 'resolved_at' => now()->subSeconds(7)->timestamp,
                'answer' => ['answer' => 'yes'],
                'payload' => ['ask_lat' => 47.50, 'ask_lng' => 19.05, 'radius_m' => 1000],
            ]],
            // …plus an archived earlier round, which the replay must also surface.
            'rounds_log' => [[
                'round' => 0,
                'questions' => [[
                    'seq' => 1, 'category' => 'thermometer', 'asked_by' => $hider->id,
                    'asked_at' => now()->subSeconds(40)->timestamp, 'resolved_at' => now()->subSeconds(38)->timestamp,
                    'answer' => ['answer' => 'hotter'],
                    'payload' => ['start_lat' => 47.49, 'start_lng' => 19.04, 'end_lat' => 47.50, 'end_lng' => 19.05],
                ]],
                'curses_played' => [],
            ]],
        ]]);

        // Two rounds → two hiding zones reconstructed from the choose_station log (state_data only keeps the last).
        $r1 = $session->actionLogs()->create(['type' => 'choose_station', 'player_id' => $hider->id, 'payload' => ['lat' => 47.50, 'lng' => 19.05]]);
        $r1->forceFill(['created_at' => now()->subSeconds(30)])->save();
        $adv = $session->actionLogs()->create(['type' => 'advance_round', 'player_id' => $hider->id, 'payload' => []]);
        $adv->forceFill(['created_at' => now()->subSeconds(12)])->save();
        $r2 = $session->actionLogs()->create(['type' => 'choose_station', 'player_id' => $hider->id, 'payload' => ['lat' => 47.52, 'lng' => 19.08]]);
        $r2->forceFill(['created_at' => now()->subSeconds(6)])->save();

        $bundle = app(ReplayBuilder::class)->build($session->fresh());
        $this->assertLessThanOrEqual($bundle['t1'], $bundle['t0']);
        $this->assertNotEmpty($bundle['players']);
        $this->assertNotEmpty($bundle['players'][1]['track'] ?? $bundle['players'][0]['track']); // the hider has a track
        $this->assertNotEmpty($bundle['questions']);
        // Both the live round's radar and the archived round's thermometer must be present (whole-game history),
        // each carrying what the deduction needs to cut: a radius for radar, an end point for thermometer.
        $cats = collect($bundle['questions'])->pluck('category');
        $this->assertTrue($cats->contains('radar'), 'radar (radius cut) present');
        $this->assertTrue($cats->contains('thermometer'), 'thermometer (archived round) present');
        $radar = collect($bundle['questions'])->firstWhere('category', 'radar');
        $thermo = collect($bundle['questions'])->firstWhere('category', 'thermometer');
        $this->assertNotNull($radar['ask']['radius_m']); // radius cut input
        $this->assertNotNull($thermo['end']);            // thermometer cut input
        $this->assertNotNull($bundle['zone']);
        $this->assertCount(2, $bundle['zones']); // one zone per round, oldest first
        $this->assertEqualsWithDelta(47.50, $bundle['zones'][0]['lat'], 1e-6);
        $this->assertEqualsWithDelta(47.52, $bundle['zones'][1]['lat'], 1e-6);
        $this->assertSame(500.0, $bundle['zones'][0]['radius_m']);
        $this->assertNotEmpty($bundle['zones'][0]['stations']); // nearby transit stops for carving the zone
        $this->assertEqualsWithDelta(47.50, $bundle['zones'][0]['stations'][0][0], 1e-4);
        $this->assertCount(2, $bundle['rounds']); // advance_round split → two round windows
        $this->assertSame(1, $bundle['rounds'][0]['round']);
        $this->assertSame($bundle['rounds'][0]['end'], $bundle['rounds'][1]['start']); // windows are contiguous
        $this->assertNotNull($bundle['playArea']); // circle fallback
        $this->assertSame('Polygon', $bundle['playAreaGeo']['type'] ?? null); // the city boundary the deduction starts from

        $this->actingAs($this->adminUser());
        $this->get(SessionResource::getUrl('replay', ['record' => $session]))
            ->assertSuccessful()
            ->assertSee('replayApp(', false)      // the Alpine timeline player is wired up
            ->assertSee('x-ref="map"', false)     // the Leaflet map container renders
            ->assertSee($session->join_code)      // page title / header
            ->assertSee('Bo')                     // the hider appears in the embedded bundle + legend
            ->assertSee('Deduction cuts')         // deduction-layer toggle
            ->assertSee('Movement trails')        // trace toggle
            ->assertSee('1× realtime', false)     // realtime playback speed option
            ->assertSee('currentAction', false)   // "Now" current-action readout
            ->assertSee('gotoRound', false)       // per-round switcher
            ->assertSee('thermoIcon', false)      // thermometer start→end markers
            ->assertSee('Thermometer start', false);
    }

    public function test_admin_can_toggle_and_revoke(): void
    {
        $this->actingAs($this->adminUser());
        $target = User::factory()->create();
        $target->createToken('t');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        Livewire::test(ListUsers::class)
            ->callTableAction('toggleAdmin', $target)
            ->callTableAction('revokeTokens', $target);

        $this->assertTrue($target->fresh()->is_admin);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_system_status_page_renders(): void
    {
        // Seed the version cache so the page doesn't make a live git/network call.
        \Illuminate\Support\Facades\Cache::put('health:version', [
            'current' => 'abc1234', 'remote' => 'abc1234', 'up_to_date' => true, 'available' => false, 'error' => null,
        ], now()->addMinutes(5));

        $this->actingAs($this->adminUser());
        $this->get(\App\Filament\Pages\SystemStatus::getUrl())
            ->assertSuccessful()
            ->assertSee('System status')
            ->assertSee('Database')          // a service row
            ->assertSee('Reverb (WebSocket)')
            ->assertSee('Check for updates') // header action
            ->assertSee('Deploy latest')     // deploy button is shown (disabled), not hidden
            ->assertSee('ADMIN_DEPLOY_ENABLED'); // hint shown while deploy is disabled
    }
}
