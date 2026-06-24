<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionApiTest extends TestCase
{
    use RefreshDatabase;

    private function signInGuest(): User
    {
        Sanctum::actingAs($user = User::factory()->create());

        return $user;
    }

    public function test_create_session_resolves_city_and_size_into_config(): void
    {
        $this->signInGuest();

        $response = $this->postJson('/api/sessions', [
            'city' => 'budapest', 'game_size' => 'medium', 'display_name' => 'Host',
        ]);

        $response->assertCreated()
            ->assertJsonPath('config.city.key', 'budapest')
            ->assertJsonPath('config.city.name', 'Budapest')
            ->assertJsonPath('config.game_size', 'medium')
            ->assertJsonPath('config.hiding_time_limit_s', 1800)
            ->assertJsonPath('players.0.display_name', 'Host')
            ->assertJsonPath('players.0.is_host', true);

        $this->assertEquals(25.0, $response->json('config.play_radius_km'));
        $this->assertNotEmpty($response->json('join_code'));
    }

    public function test_create_validates_city_and_size(): void
    {
        $this->signInGuest();

        $this->postJson('/api/sessions', ['city' => 'paris', 'game_size' => 'medium'])
            ->assertStatus(422)->assertJsonValidationErrors(['city']);

        $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'huge'])
            ->assertStatus(422)->assertJsonValidationErrors(['game_size']);
    }

    public function test_join_by_code_adds_a_player(): void
    {
        $this->signInGuest();
        $code = $this->postJson('/api/sessions', ['city' => 'szeged', 'game_size' => 'small'])->json('join_code');

        Sanctum::actingAs(User::factory()->create());
        $response = $this->postJson("/api/sessions/{$code}/join", ['display_name' => 'Bob']);

        $response->assertOk()->assertJsonPath('player.display_name', 'Bob');
        $this->assertCount(2, $response->json('session.players'));
    }

    public function test_join_with_unknown_code_is_404(): void
    {
        $this->signInGuest();

        $this->postJson('/api/sessions/ZZZZZZ/join', ['display_name' => 'Bob'])->assertNotFound();
    }

    public function test_show_and_state_endpoints(): void
    {
        $this->signInGuest();
        $id = $this->postJson('/api/sessions', ['city' => 'pecs', 'game_size' => 'large'])->json('id');

        $this->getJson("/api/sessions/{$id}")->assertOk()->assertJsonPath('id', $id);

        $this->getJson("/api/sessions/{$id}/state")
            ->assertOk()
            ->assertJsonPath('session_id', $id)
            ->assertJsonPath('state', 'lobby')
            ->assertJsonPath('available_actions', ['start', 'end_game']); // host in lobby
    }
}
