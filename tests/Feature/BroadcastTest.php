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
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hostPlayerId = $create->json('players.0.id');

        $this->postJson("/api/sessions/{$sessionId}/start");
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hostPlayerId]]);
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'confirm_hidden']);

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'RoundStarted' && $e->sessionId === $sessionId);
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'HidingStarted');
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'SeekingStarted');
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
        $this->postJson('/api/broadcasting/auth')->assertUnauthorized();
    }
}
