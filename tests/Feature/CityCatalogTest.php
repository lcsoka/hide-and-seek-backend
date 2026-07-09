<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_cities_endpoint_returns_seeded_cities_with_size_and_modes(): void
    {
        $res = $this->getJson('/api/v1/cities')->assertOk();

        $this->assertCount(10, $res->json());

        $budapest = collect($res->json())->firstWhere('key', 'budapest');
        $this->assertSame('Budapest', $budapest['name']);
        $this->assertSame('medium', $budapest['size']);
        $this->assertContains('metro', $budapest['modes']);
        $this->assertNull($budapest['image']); // no photo uploaded yet

        // A smaller city has no metro.
        $szeged = collect($res->json())->firstWhere('key', 'szeged');
        $this->assertSame('small', $szeged['size']);
        $this->assertNotContains('metro', $szeged['modes']);
        $this->assertContains('rail', $szeged['modes']); // train everywhere
    }

    public function test_creating_a_session_in_an_unknown_city_is_rejected(): void
    {
        $user = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson('/api/v1/sessions', ['city' => 'atlantis', 'game_size' => 'medium'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('city');
    }
}
