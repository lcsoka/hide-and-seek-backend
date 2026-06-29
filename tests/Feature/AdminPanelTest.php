<?php

namespace Tests\Feature;

use App\Enums\GameMode;
use App\Enums\SessionStatus;
use App\Filament\Widgets\GameStatsOverview;
use App\Filament\Widgets\RecentSessions;
use App\Filament\Widgets\SessionsChart;
use App\Models\Card;
use App\Models\Feedback;
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
            'config' => ['rounds' => 3, 'endgame_radius_m' => 500],
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
}
