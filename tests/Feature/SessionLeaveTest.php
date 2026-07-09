<?php

namespace Tests\Feature;

use App\Models\GameEvent;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

class SessionLeaveTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    /** @return array{sessionId: string, code: string, host: User} */
    private function lobby(): array
    {
        Sanctum::actingAs($host = User::factory()->create());
        $create = $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'display_name' => 'Host'])->assertCreated();

        return ['sessionId' => $create->json('id'), 'code' => $create->json('join_code'), 'host' => $host];
    }

    public function test_non_host_leaving_the_lobby_is_removed_and_announced(): void
    {
        ['sessionId' => $sessionId, 'code' => $code] = $this->lobby();

        Sanctum::actingAs($leaver = User::factory()->create());
        $playerId = $this->postJson("/api/v1/sessions/{$code}/join", ['display_name' => 'Leaver'])->json('player.id');

        $this->postJson("/api/v1/sessions/{$sessionId}/leave")->assertNoContent();

        $this->assertDatabaseMissing('players', ['id' => $playerId]);
        $this->assertDatabaseHas('game_events', ['session_id' => $sessionId, 'type' => 'PlayerLeft']);
    }

    public function test_the_host_is_never_removed_when_leaving_the_lobby(): void
    {
        ['sessionId' => $sessionId, 'host' => $host] = $this->lobby();
        $hostPlayerId = Session::find($sessionId)->host_player_id;

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/sessions/{$sessionId}/leave")->assertNoContent();

        $this->assertDatabaseHas('players', ['id' => $hostPlayerId]);
    }

    public function test_a_player_leaving_mid_game_is_kept(): void
    {
        $ctx = $this->startSeeking();

        // The seeker "leaves" (closes the app) once the game is live — they're kept so they can
        // reconnect, not dropped from an in-progress round.
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/leave")->assertNoContent();

        $this->assertDatabaseHas('players', ['id' => $ctx['seekerId']]);
        $this->assertDatabaseMissing('game_events', ['session_id' => $ctx['sessionId'], 'type' => 'PlayerLeft']);
    }

    public function test_leaving_is_idempotent(): void
    {
        ['sessionId' => $sessionId, 'code' => $code] = $this->lobby();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/sessions/{$code}/join", ['display_name' => 'Leaver']);

        $this->postJson("/api/v1/sessions/{$sessionId}/leave")->assertNoContent();
        // Second leave (no player left to remove) is still a clean no-op, not an error.
        $this->postJson("/api/v1/sessions/{$sessionId}/leave")->assertNoContent();

        $this->assertSame(1, GameEvent::where('session_id', $sessionId)->where('type', 'PlayerLeft')->count());
    }
}
