<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameplayTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionId;

    private string $hostPlayerId;

    private string $seekerPlayerId;

    private User $host;

    private User $seeker;

    /** Create a 2-player, single-round session; returns nothing but sets ids. */
    private function setUpSession(): void
    {
        $this->host = User::factory()->create();
        Sanctum::actingAs($this->host);
        $create = $this->postJson('/api/sessions', [
            'city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1], 'display_name' => 'Host',
        ])->assertCreated();
        $this->sessionId = $create->json('id');
        $this->hostPlayerId = $create->json('players.0.id');
        $code = $create->json('join_code');

        $this->seeker = User::factory()->create();
        Sanctum::actingAs($this->seeker);
        $this->seekerPlayerId = $this->postJson("/api/sessions/{$code}/join", ['display_name' => 'Seeker'])
            ->assertOk()->json('player.id');
    }

    private function action(string $type, array $payload = [])
    {
        return $this->postJson("/api/sessions/{$this->sessionId}/actions", ['type' => $type, 'payload' => $payload]);
    }

    public function test_full_round_progresses_lobby_to_finished(): void
    {
        $this->setUpSession();

        // Host starts -> role assignment.
        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start")->assertOk()->assertJsonPath('state', 'role_assignment');

        // Host assigns themselves as hider -> hiding.
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId])->assertOk()->assertJsonPath('state', 'hiding');

        // Hider (host) confirms hidden -> seeking.
        $this->action('confirm_hidden')->assertOk()->assertJsonPath('state', 'seeking');

        // Seeker asks a question (logged, no transition).
        Sanctum::actingAs($this->seeker);
        $this->action('ask_question', ['category' => 'radar', 'radius' => '1 mile'])
            ->assertOk()->assertJsonPath('state', 'seeking');

        // Seeker declares endgame -> endgame.
        $this->action('declare_endgame')->assertOk()->assertJsonPath('state', 'endgame');

        // Hider surrenders -> round_end.
        Sanctum::actingAs($this->host);
        $this->action('surrender')->assertOk()->assertJsonPath('state', 'round_end');

        // Host advances -> finished (single round).
        $this->action('advance_round')->assertOk()
            ->assertJsonPath('state', 'finished')
            ->assertJsonPath('status', 'finished');
    }

    public function test_make_guess_within_radius_ends_the_round(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId]);
        $this->action('confirm_hidden');

        // Hider's known location (no location endpoint yet this slice).
        Player::whereKey($this->hostPlayerId)->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);

        Sanctum::actingAs($this->seeker);
        $this->action('declare_endgame')->assertJsonPath('state', 'endgame');
        $this->action('make_guess', ['lat' => 47.4979, 'lng' => 19.0402])
            ->assertOk()->assertJsonPath('state', 'round_end');
    }

    public function test_non_host_cannot_start(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->seeker);
        $this->postJson("/api/sessions/{$this->sessionId}/start")
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_illegal_action_for_state_is_rejected(): void
    {
        $this->setUpSession();

        // confirm_hidden is not available in the lobby.
        Sanctum::actingAs($this->host);
        $this->action('confirm_hidden')->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_available_actions_are_player_and_state_specific(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->host);
        $this->getJson("/api/sessions/{$this->sessionId}/state")
            ->assertOk()->assertJsonPath('available_actions', ['start']);

        Sanctum::actingAs($this->seeker);
        $this->getJson("/api/sessions/{$this->sessionId}/state")
            ->assertOk()->assertJsonPath('available_actions', []);
    }
}
