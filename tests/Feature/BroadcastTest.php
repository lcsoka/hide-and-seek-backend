<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_actions_broadcast_events_on_the_session_channel(): void
    {
        Event::fake([GameEventBroadcast::class]);

        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hostPlayerId = $create->json('players.0.id');

        $this->postJson("/api/v1/sessions/{$sessionId}/start");
        $this->postJson("/api/v1/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hostPlayerId]]);
        $this->postJson("/api/v1/sessions/{$sessionId}/actions", ['type' => 'confirm_hidden']);

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'RoundStarted' && $e->sessionId === $sessionId);
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'HidingStarted');
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'SeekingStarted');
    }

    public function test_joining_broadcasts_player_joined_so_the_lobby_updates_live(): void
    {
        Event::fake([GameEventBroadcast::class]);

        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $code = $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'small'])->json('join_code');

        Sanctum::actingAs(User::factory()->create());
        $playerId = $this->postJson("/api/v1/sessions/{$code}/join", ['display_name' => 'Bo'])->json('player.id');

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'PlayerJoined' && ($e->payload['player_id'] ?? null) === $playerId);
    }

    public function test_event_targets_the_session_presence_channel(): void
    {
        $event = new GameEventBroadcast('abc', 'SeekingStarted', ['round' => 0]);

        $this->assertSame('presence-session.abc', $event->broadcastOn()[0]->name);
        $this->assertSame('SeekingStarted', $event->broadcastAs());
        $this->assertSame(['round' => 0], $event->broadcastWith());
    }

    public function test_broadcasting_auth_requires_authentication(): void
    {
        $this->postJson('/api/v1/broadcasting/auth')->assertUnauthorized();
    }

    public function test_player_scoped_events_target_the_private_player_channel(): void
    {
        Event::fake([GameEventBroadcast::class]);

        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hostPlayerId = $create->json('players.0.id');

        $this->postJson("/api/v1/sessions/{$sessionId}/start");
        $this->postJson("/api/v1/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hostPlayerId]]);
        $this->postJson("/api/v1/sessions/{$sessionId}/actions", ['type' => 'choose_station', 'payload' => ['lat' => 47.4979, 'lng' => 19.0402]]);

        // The hider's zone goes only to the hider's private channel...
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'HidingZoneChosen'
            && $e->broadcastOn()[0]->name === "private-session.{$sessionId}.player.{$hostPlayerId}");

        // ...while a normal event stays on the shared presence channel.
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'HidingStarted'
            && $e->broadcastOn()[0]->name === "presence-session.{$sessionId}");
    }
}
