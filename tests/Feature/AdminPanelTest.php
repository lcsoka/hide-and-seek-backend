<?php

namespace Tests\Feature;

use App\Enums\GameMode;
use App\Enums\SessionStatus;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

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
        $this->actingAs(User::factory()->create());

        foreach (['sessions', 'players', 'teams', 'action-logs'] as $slug) {
            $this->get("/admin/{$slug}")->assertSuccessful();
        }
    }

    public function test_session_edit_page_with_relation_managers_loads(): void
    {
        $session = $this->seedSession();
        $this->actingAs(User::factory()->create());

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
}
