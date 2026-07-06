<?php

namespace Tests\Feature;

use App\Game\Geo\ArrayMapDataSource;
use App\Game\Geo\GeoFeature;
use App\Game\Geo\MapDataSource;
use App\Models\Question;
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

        $this->debug()->getJson("/api/v1/sessions/{$session->id}/debug/state")->assertNotFound();
    }

    public function test_debug_api_requires_the_developer_token(): void
    {
        $session = $this->makeSession();

        $this->getJson("/api/v1/sessions/{$session->id}/debug/state")->assertForbidden();
        $this->withHeaders(['X-Developer-Token' => 'wrong'])
            ->getJson("/api/v1/sessions/{$session->id}/debug/state")->assertForbidden();
    }

    public function test_god_view_exposes_unfiltered_state(): void
    {
        $session = $this->makeSession();

        $this->debug()->getJson("/api/v1/sessions/{$session->id}/debug/state")
            ->assertOk()
            ->assertJsonPath('state', 'lobby')
            ->assertJsonStructure(['state_data', 'players', 'config']);
    }

    public function test_seed_players_creates_bots(): void
    {
        $session = $this->makeSession();

        $this->debug()->postJson("/api/v1/sessions/{$session->id}/debug/seed-players", ['count' => 3])->assertOk();

        $this->assertSame(4, Session::find($session->id)->players()->count()); // host + 3 bots
    }

    public function test_spoof_location(): void
    {
        $session = $this->makeSession();
        $player = $session->players()->first();

        $this->debug()->postJson("/api/v1/sessions/{$session->id}/debug/location", [
            'player_id' => $player->id, 'lat' => 47.5, 'lng' => 19.0,
        ])->assertOk();

        $this->assertEquals(47.5, $player->fresh()->last_lat);
    }

    public function test_force_state(): void
    {
        $session = $this->makeSession();

        $this->debug()->postJson("/api/v1/sessions/{$session->id}/debug/state", ['state' => 'seeking'])
            ->assertOk()->assertJsonPath('state', 'seeking');
    }

    public function test_eval_question_returns_a_tentacles_answer_with_candidates(): void
    {
        $session = $this->makeSession();
        $question = Question::create([
            'key' => 'tentacles.museums_2_km', 'category' => 'tentacles',
            'title' => ['en' => 'Q'], 'prompt' => ['en' => 'Q'],
            'parameters' => ['feature' => 'museum', 'radius_m' => 2000],
        ]);
        // Two museums within 2 km of the seeker; the hider matches the nearer one.
        $this->app->instance(MapDataSource::class, new ArrayMapDataSource([
            new GeoFeature('m/1', 'museum', 47.5020, 19.0020, 'Museum One'),
            new GeoFeature('m/2', 'museum', 47.5060, 19.0080, 'Museum Two'),
        ]));

        $this->debug()->postJson("/api/v1/sessions/{$session->id}/debug/eval-question", [
            'question_id' => $question->id,
            'seeker_lat' => 47.5000, 'seeker_lng' => 19.0000,
            'hider_lat' => 47.5055, 'hider_lng' => 19.0075,
        ])->assertOk()
            ->assertJsonPath('category', 'tentacles')
            ->assertJsonPath('answer', 'in_range')
            ->assertJsonPath('matched.name', 'Museum Two') // the candidate nearest the hider
            ->assertJsonCount(2, 'candidates');
    }

    public function test_eval_question_reveals_both_places_for_matching(): void
    {
        $session = $this->makeSession();
        $question = Question::create([
            'key' => 'matching.museum', 'category' => 'matching',
            'title' => ['en' => 'Q'], 'prompt' => ['en' => 'Q'],
            'parameters' => ['feature' => 'museum'],
        ]);
        // Distinct nearest museums for hider vs seeker → "no".
        $this->app->instance(MapDataSource::class, new ArrayMapDataSource([
            new GeoFeature('m/s', 'museum', 47.5001, 19.0001, 'Seeker Museum'),
            new GeoFeature('m/h', 'museum', 47.6001, 19.2001, 'Hider Museum'),
        ]));

        $this->debug()->postJson("/api/v1/sessions/{$session->id}/debug/eval-question", [
            'question_id' => $question->id,
            'seeker_lat' => 47.5000, 'seeker_lng' => 19.0000,
            'hider_lat' => 47.6000, 'hider_lng' => 19.2000,
        ])->assertOk()
            ->assertJsonPath('answer', 'no')
            ->assertJsonPath('matched.name', 'Seeker Museum')
            ->assertJsonPath('hider_nearest.name', 'Hider Museum');
    }

    public function test_mint_token_lets_a_dev_spectate_a_players_view(): void
    {
        $session = $this->makeSession();
        $bot = $session->players()->create(['display_name' => 'Bot 1']); // no user yet

        $res = $this->debug()->postJson("/api/v1/sessions/{$session->id}/debug/token", ['player_id' => $bot->id])
            ->assertOk()
            ->assertJsonStructure(['player_id', 'token']);

        // The bot got a user, and the minted token authenticates as that player.
        $this->assertNotNull($bot->fresh()->user_id);
        $this->withToken($res->json('token'))
            ->getJson("/api/v1/sessions/{$session->id}/state")->assertOk();
    }

    public function test_resolve_code_returns_the_god_view(): void
    {
        $session = $this->makeSession();

        $this->debug()->getJson("/api/v1/debug/sessions/{$session->join_code}")
            ->assertOk()
            ->assertJsonPath('session_id', $session->id)
            ->assertJsonStructure(['players', 'state']);

        // case-insensitive, and unknown codes 404
        $this->debug()->getJson('/api/v1/debug/sessions/'.strtolower($session->join_code))->assertOk();
        $this->debug()->getJson('/api/v1/debug/sessions/ZZZZZZ')->assertNotFound();
    }

    public function test_act_as_any_player(): void
    {
        $session = $this->makeSession();
        $host = $session->players()->where('is_host', true)->first();

        $this->debug()->postJson("/api/v1/sessions/{$session->id}/debug/act-as", [
            'player_id' => $host->id, 'type' => 'start',
        ])->assertOk()->assertJsonPath('state', 'role_assignment');
    }
}
