<?php

namespace Tests\Feature;

use App\Models\GameResult;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_finishing_a_game_records_a_durable_result_for_players(): void
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $id = $create->json('id');
        $hostPid = $create->json('players.0.id');
        $this->postJson("/api/sessions/{$id}/start");

        // Bank some hiding time for the host so the recorded result is meaningful.
        $session = Session::find($id);
        $data = $session->state_data;
        $data['scores'] = [$hostPid => 90];
        $session->state_data = $data;
        $session->save();

        $this->postJson("/api/sessions/{$id}/actions", ['type' => 'end_game'])->assertOk()->assertJsonPath('state', 'finished');

        $this->assertDatabaseHas('game_results', [
            'user_id' => $host->id,
            'session_id' => $id,
            'hide_time_s' => 90,
            'won' => true,
        ]);
    }

    public function test_the_result_survives_the_session_being_pruned(): void
    {
        $user = User::factory()->create();
        $session = Session::create(['join_code' => 'ABCDEF', 'game_mode' => 'hide_and_seek', 'state' => 'finished', 'status' => 'finished', 'config' => [], 'state_data' => []]);
        $result = GameResult::create(['user_id' => $user->id, 'session_id' => $session->id, 'display_name' => 'X', 'hide_time_s' => 30, 'won' => false, 'players_count' => 2, 'played_at' => now()]);

        $session->delete(); // retention prune

        // The result row is kept (session_id nulled), so history/stats persist.
        $this->assertDatabaseHas('game_results', ['id' => $result->id, 'user_id' => $user->id, 'session_id' => null]);
    }

    public function test_stats_endpoint_aggregates_and_lists_recent(): void
    {
        $user = User::factory()->create();
        GameResult::create(['user_id' => $user->id, 'display_name' => 'X', 'hide_time_s' => 120, 'won' => true, 'players_count' => 3, 'played_at' => now()->subDay()]);
        GameResult::create(['user_id' => $user->id, 'display_name' => 'X', 'hide_time_s' => 60, 'won' => false, 'players_count' => 2, 'played_at' => now()]);
        // Another user's results must not leak in.
        GameResult::create(['user_id' => User::factory()->create()->id, 'display_name' => 'Y', 'hide_time_s' => 999, 'won' => true, 'players_count' => 2, 'played_at' => now()]);

        Sanctum::actingAs($user);
        $this->getJson('/api/profile/stats')->assertOk()
            ->assertJsonPath('games_played', 2)
            ->assertJsonPath('wins', 1)
            ->assertJsonPath('total_hide_time_s', 180)
            ->assertJsonPath('best_hide_time_s', 120)
            ->assertJsonPath('recent.0.hide_time_s', 60); // most recent first
    }
}
