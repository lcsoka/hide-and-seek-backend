<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_get_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/guest', ['display_name' => 'Anna']);

        $response->assertCreated()->assertJsonStructure(['token', 'display_name', 'user_id']);
        $this->assertSame('Anna', $response->json('display_name'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_guest_token_authenticates_requests(): void
    {
        $token = $this->postJson('/api/v1/auth/guest', [])->json('token');

        $this->withToken($token)
            ->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'medium'])
            ->assertCreated();
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'medium'])
            ->assertUnauthorized();
    }
}
