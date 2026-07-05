<?php

namespace Tests\Feature;

use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionRejoinTest extends TestCase
{
    use RefreshDatabase;

    private function hostAGame(): string
    {
        Sanctum::actingAs(User::factory()->create());

        return $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'small'])->json('join_code');
    }

    public function test_rejoining_the_same_session_resumes_the_same_player(): void
    {
        $code = $this->hostAGame();
        $guest = User::factory()->create();
        Sanctum::actingAs($guest);

        $first = $this->postJson("/api/v1/sessions/{$code}/join", ['display_name' => 'Ann'])->assertOk()->json('player.id');
        $second = $this->postJson("/api/v1/sessions/{$code}/join", ['display_name' => 'Ann'])->assertOk()->json('player.id');

        $this->assertSame($first, $second); // same player, not a duplicate
        $session = Session::where('join_code', $code)->first();
        $this->assertSame(1, $session->players()->where('user_id', $guest->id)->count());
        $this->assertSame(2, $session->players()->count()); // host + this one
    }

    public function test_my_sessions_lists_the_users_live_games(): void
    {
        $code = $this->hostAGame(); // leaves the host authenticated

        $res = $this->getJson('/api/v1/my/sessions')->assertOk();
        $res->assertJsonCount(1);
        $res->assertJsonPath('0.join_code', $code);
        $res->assertJsonPath('0.is_host', true);
        $this->assertNotNull($res->json('0.player_id'));
    }

    public function test_my_sessions_excludes_finished_games(): void
    {
        $this->hostAGame();
        Session::query()->update(['status' => 'finished']);

        $this->getJson('/api/v1/my/sessions')->assertOk()->assertJsonCount(0);
    }
}
