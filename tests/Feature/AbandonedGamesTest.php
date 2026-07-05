<?php

namespace Tests\Feature;

use App\Enums\SessionStatus;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $create = $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);

        return [$create->json('id'), $create->json('players.0.id')];
    }

    // ── explicit host stop ───────────────────────────────────────────────────

    public function test_status_becomes_running_on_start(): void
    {
        [$id] = $this->hostSession();
        $this->assertSame(SessionStatus::Open, Session::find($id)->status);

        $this->postJson("/api/v1/sessions/{$id}/start")->assertOk();

        $this->assertSame(SessionStatus::Running, Session::find($id)->status);
    }

    public function test_host_can_end_the_game(): void
    {
        [$id] = $this->hostSession();
        $this->postJson("/api/v1/sessions/{$id}/start");

        $this->postJson("/api/v1/sessions/{$id}/actions", ['type' => 'end_game'])
            ->assertOk()->assertJsonPath('state', 'finished')->assertJsonPath('status', 'finished');

        $this->assertNotNull(Session::find($id)->ended_at);
    }

    public function test_actions_and_locations_record_activity(): void
    {
        [$id] = $this->hostSession();
        $this->assertNull(Session::find($id)->last_activity_at);

        $this->postJson("/api/v1/sessions/{$id}/start");
        $this->assertNotNull(Session::find($id)->last_activity_at);

        $before = Session::find($id)->last_activity_at;
        $this->travel(1)->minute();
        $this->postJson("/api/v1/sessions/{$id}/location", ['lat' => 47.5, 'lng' => 19.0])->assertNoContent();
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

    // ── guest / token cruft ─────────────────────────────────────────────────────

    private function makeGuest(string $name, ?\Carbon\CarbonInterface $createdAt = null): User
    {
        $guest = User::create(['name' => $name]); // no email = guest
        $guest->createToken('guest');
        if ($createdAt !== null) {
            $guest->forceFill(['created_at' => $createdAt])->save();
        }

        return $guest;
    }

    public function test_prune_removes_old_orphan_guests_and_their_tokens(): void
    {
        $guest = $this->makeGuest('Old Guest', now()->subDays(8)); // > 7d guest retention, no session
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $guest->id]);

        $this->artisan('game:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $guest->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $guest->id]);
    }

    public function test_prune_keeps_registered_users_recent_guests_and_guests_in_live_games(): void
    {
        $registered = User::factory()->create(); // has email — never pruned
        $recentGuest = $this->makeGuest('Recent', now()->subHours(1)); // inside 7d retention

        // A guest currently in a live (open) session must be kept even if old.
        $liveGuest = $this->makeGuest('Playing', now()->subDays(30));
        $session = $this->makeSession(['status' => 'running', 'state' => 'seeking']);
        Player::create(['session_id' => $session->id, 'user_id' => $liveGuest->id, 'display_name' => 'Playing']);

        $this->artisan('game:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $registered->id]);
        $this->assertDatabaseHas('users', ['id' => $recentGuest->id]);
        $this->assertDatabaseHas('users', ['id' => $liveGuest->id]);
    }

    public function test_purge_deletes_terminal_now_and_prunes_recent_orphan_guests(): void
    {
        $recentTerminal = $this->makeSession(['status' => 'finished', 'state' => 'finished', 'ended_at' => now()->subMinutes(5)]);
        $recentGuest = $this->makeGuest('Fresh', now()->subMinutes(5)); // would survive normal run

        $this->artisan('game:prune-abandoned', ['--purge' => true])->assertSuccessful();

        $this->assertDatabaseMissing('game_sessions', ['id' => $recentTerminal->id]);
        $this->assertDatabaseMissing('users', ['id' => $recentGuest->id]);
    }

    public function test_idle_zero_abandons_every_idle_session(): void
    {
        $lobby = $this->makeSession(['last_activity_at' => now()->subMinutes(1)]);
        $active = $this->makeSession(['state' => 'seeking', 'status' => 'running', 'last_activity_at' => now()->subMinutes(1)]);

        $this->artisan('game:prune-abandoned', ['--idle' => '0'])->assertSuccessful();

        $this->assertSame(SessionStatus::Abandoned, $lobby->fresh()->status);
        $this->assertSame(SessionStatus::Abandoned, $active->fresh()->status);
    }

    public function test_prune_deletes_expired_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->accessToken;
        DB::table('personal_access_tokens')->where('id', $token->id)->update(['expires_at' => now()->subDay()]);

        $this->artisan('game:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }
}
