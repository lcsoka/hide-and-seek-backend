<?php

namespace Tests\Feature;

use App\Enums\SessionStatus;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AbandonedGamesTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(array $attrs = []): Session
    {
        return Session::create(array_merge([
            'join_code' => strtoupper(Str::random(6)),
            'game_mode' => 'hide_and_seek',
            'state' => 'lobby',
            'status' => 'open',
            'config' => [],
            'state_data' => [],
        ], $attrs));
    }

    /** @return array{0: string, 1: string} [sessionId, hostPlayerId] */
    private function hostSession(): array
    {
        Sanctum::actingAs(User::factory()->create());
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);

        return [$create->json('id'), $create->json('players.0.id')];
    }

    // ── explicit host stop ───────────────────────────────────────────────────

    public function test_status_becomes_running_on_start(): void
    {
        [$id] = $this->hostSession();
        $this->assertSame(SessionStatus::Open, Session::find($id)->status);

        $this->postJson("/api/sessions/{$id}/start")->assertOk();

        $this->assertSame(SessionStatus::Running, Session::find($id)->status);
    }

    public function test_host_can_end_the_game(): void
    {
        [$id] = $this->hostSession();
        $this->postJson("/api/sessions/{$id}/start");

        $this->postJson("/api/sessions/{$id}/actions", ['type' => 'end_game'])
            ->assertOk()->assertJsonPath('state', 'finished')->assertJsonPath('status', 'finished');

        $this->assertNotNull(Session::find($id)->ended_at);
    }

    public function test_actions_and_locations_record_activity(): void
    {
        [$id] = $this->hostSession();
        $this->assertNull(Session::find($id)->last_activity_at);

        $this->postJson("/api/sessions/{$id}/start");
        $this->assertNotNull(Session::find($id)->last_activity_at);

        $before = Session::find($id)->last_activity_at;
        $this->travel(1)->minute();
        $this->postJson("/api/sessions/{$id}/location", ['lat' => 47.5, 'lng' => 19.0])->assertNoContent();
        $this->assertTrue(Session::find($id)->last_activity_at->gt($before));
    }

    // ── automatic pruning ──────────────────────────────────────────────────────

    public function test_prune_marks_idle_lobby_session_abandoned(): void
    {
        $session = $this->makeSession(['last_activity_at' => now()->subHours(3)]); // > 2h lobby threshold

        $this->artisan('game:prune-abandoned')->assertSuccessful();

        $this->assertSame(SessionStatus::Abandoned, $session->fresh()->status);
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_prune_marks_idle_active_session_abandoned(): void
    {
        $session = $this->makeSession(['state' => 'seeking', 'status' => 'running', 'last_activity_at' => now()->subHours(7)]);

        $this->artisan('game:prune-abandoned')->assertSuccessful();

        $this->assertSame(SessionStatus::Abandoned, $session->fresh()->status);
    }

    public function test_prune_keeps_recently_active_sessions(): void
    {
        $lobby = $this->makeSession(['last_activity_at' => now()->subMinutes(10)]);
        $active = $this->makeSession(['state' => 'seeking', 'status' => 'running', 'last_activity_at' => now()->subHours(2)]);

        $this->artisan('game:prune-abandoned')->assertSuccessful();

        $this->assertSame(SessionStatus::Open, $lobby->fresh()->status);
        $this->assertSame(SessionStatus::Running, $active->fresh()->status);
    }

    public function test_prune_deletes_old_terminal_sessions_but_keeps_recent(): void
    {
        $old = $this->makeSession(['status' => 'finished', 'state' => 'finished', 'ended_at' => now()->subDays(31)]);
        $recent = $this->makeSession(['status' => 'finished', 'state' => 'finished', 'ended_at' => now()->subDays(2)]);

        $this->artisan('game:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseMissing('game_sessions', ['id' => $old->id]);
        $this->assertDatabaseHas('game_sessions', ['id' => $recent->id]);
    }
}
