<?php

namespace Tests\Feature;

use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DebugApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(): Session
    {
        $session = Session::create([
            'join_code' => strtoupper(Str::random(6)),
            'game_mode' => 'hide_and_seek',
            'state' => 'lobby',
            'status' => 'open',
            'config' => ['rounds' => 1],
            'state_data' => ['round' => 0],
        ]);
        $session->players()->create(['display_name' => 'Host', 'is_host' => true]);

        return $session;
    }

    private function debug()
    {
        return $this->withHeaders(['X-Developer-Token' => 'test-debug-token']);
    }

    public function test_debug_api_is_hidden_when_disabled(): void
    {
        config(['game.debug.enabled' => false]);
        $session = $this->makeSession();

        $this->debug()->getJson("/api/sessions/{$session->id}/debug/state")->assertNotFound();
    }

    public function test_debug_api_requires_the_developer_token(): void
    {
        $session = $this->makeSession();

        $this->getJson("/api/sessions/{$session->id}/debug/state")->assertForbidden();
        $this->withHeaders(['X-Developer-Token' => 'wrong'])
            ->getJson("/api/sessions/{$session->id}/debug/state")->assertForbidden();
    }

    public function test_god_view_exposes_unfiltered_state(): void
    {
        $session = $this->makeSession();

        $this->debug()->getJson("/api/sessions/{$session->id}/debug/state")
            ->assertOk()
            ->assertJsonPath('state', 'lobby')
            ->assertJsonStructure(['state_data', 'players', 'config']);
    }

    public function test_seed_players_creates_bots(): void
    {
        $session = $this->makeSession();

        $this->debug()->postJson("/api/sessions/{$session->id}/debug/seed-players", ['count' => 3])->assertOk();

        $this->assertSame(4, Session::find($session->id)->players()->count()); // host + 3 bots
    }

    public function test_spoof_location(): void
    {
        $session = $this->makeSession();
        $player = $session->players()->first();

        $this->debug()->postJson("/api/sessions/{$session->id}/debug/location", [
            'player_id' => $player->id, 'lat' => 47.5, 'lng' => 19.0,
        ])->assertOk();

        $this->assertEquals(47.5, $player->fresh()->last_lat);
    }

    public function test_force_state(): void
    {
        $session = $this->makeSession();

        $this->debug()->postJson("/api/sessions/{$session->id}/debug/state", ['state' => 'seeking'])
            ->assertOk()->assertJsonPath('state', 'seeking');
    }

    public function test_mint_token_lets_a_dev_spectate_a_players_view(): void
    {
        $session = $this->makeSession();
        $bot = $session->players()->create(['display_name' => 'Bot 1']); // no user yet

        $res = $this->debug()->postJson("/api/sessions/{$session->id}/debug/token", ['player_id' => $bot->id])
            ->assertOk()
            ->assertJsonStructure(['player_id', 'token']);

        // The bot got a user, and the minted token authenticates as that player.
        $this->assertNotNull($bot->fresh()->user_id);
        $this->withToken($res->json('token'))
            ->getJson("/api/sessions/{$session->id}/state")->assertOk();
    }

    public function test_resolve_code_returns_the_god_view(): void
    {
        $session = $this->makeSession();

        $this->debug()->getJson("/api/debug/sessions/{$session->join_code}")
            ->assertOk()
            ->assertJsonPath('session_id', $session->id)
            ->assertJsonStructure(['players', 'state']);

        // case-insensitive, and unknown codes 404
        $this->debug()->getJson('/api/debug/sessions/'.strtolower($session->join_code))->assertOk();
        $this->debug()->getJson('/api/debug/sessions/ZZZZZZ')->assertNotFound();
    }

    public function test_act_as_any_player(): void
    {
        $session = $this->makeSession();
        $host = $session->players()->where('is_host', true)->first();

        $this->debug()->postJson("/api/sessions/{$session->id}/debug/act-as", [
            'player_id' => $host->id, 'type' => 'start',
        ])->assertOk()->assertJsonPath('state', 'role_assignment');
    }
}
