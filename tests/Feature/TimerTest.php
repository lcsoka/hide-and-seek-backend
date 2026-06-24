<?php

namespace Tests\Feature;

use App\Game\GameEngine;
use App\Jobs\FireGameTimer;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TimerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string} [sessionId, hostPlayerId] in state=hiding */
    private function startHiding(): array
    {
        Sanctum::actingAs(User::factory()->create());
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hostPlayerId = $create->json('players.0.id');

        $this->postJson("/api/sessions/{$sessionId}/start");
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hostPlayerId]]);

        return [$sessionId, $hostPlayerId];
    }

    public function test_assign_hider_schedules_the_hiding_deadline_timer(): void
    {
        [$sessionId] = $this->startHiding();

        Queue::assertPushed(FireGameTimer::class, fn ($job) => $job->key === 'hiding_deadline' && $job->sessionId === $sessionId);
    }

    public function test_hiding_deadline_timer_advances_to_seeking(): void
    {
        [$sessionId] = $this->startHiding();
        $session = Session::find($sessionId);
        $this->assertSame('hiding', $session->state);

        app(GameEngine::class)->fireTimer($session, 'hiding_deadline', $session->state_data['hiding_deadline']);

        $this->assertSame('seeking', Session::find($sessionId)->state);
    }

    public function test_timer_is_ignored_after_early_confirm(): void
    {
        [$sessionId] = $this->startHiding();
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'confirm_hidden']);
        $session = Session::find($sessionId);
        $this->assertSame('seeking', $session->state);

        // Guard still matches, but state is no longer "hiding" -> no-op.
        app(GameEngine::class)->fireTimer($session, 'hiding_deadline', $session->state_data['hiding_deadline'] ?? 0);

        $this->assertSame('seeking', Session::find($sessionId)->state);
    }

    public function test_timer_with_mismatched_guard_is_ignored(): void
    {
        [$sessionId] = $this->startHiding();
        $session = Session::find($sessionId);

        app(GameEngine::class)->fireTimer($session, 'hiding_deadline', 123);

        $this->assertSame('hiding', Session::find($sessionId)->state);
    }
}
