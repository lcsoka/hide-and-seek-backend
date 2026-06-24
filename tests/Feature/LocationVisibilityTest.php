<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionId;

    private string $hostPlayerId;

    private string $seekerPlayerId;

    private User $host;

    private User $seeker;

    private function setUpSession(): void
    {
        $this->host = User::factory()->create();
        Sanctum::actingAs($this->host);
        $create = $this->postJson('/api/sessions', [
            'city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1],
        ])->assertCreated();
        $this->sessionId = $create->json('id');
        $this->hostPlayerId = $create->json('players.0.id');

        $this->seeker = User::factory()->create();
        Sanctum::actingAs($this->seeker);
        $this->seekerPlayerId = $this->postJson("/api/sessions/{$create->json('join_code')}/join", ['display_name' => 'Seeker'])
            ->json('player.id');
    }

    private function report(float $lat, float $lng)
    {
        return $this->postJson("/api/sessions/{$this->sessionId}/location", ['lat' => $lat, 'lng' => $lng]);
    }

    private function statePlayers(): Collection
    {
        return collect($this->getJson("/api/sessions/{$this->sessionId}/state")->json('players'))->keyBy('id');
    }

    public function test_player_can_report_location(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->seeker);
        $this->report(47.5, 19.05)->assertNoContent();

        $this->assertEquals(47.5, Player::find($this->seekerPlayerId)->last_lat);
    }

    public function test_location_requires_being_a_participant(): void
    {
        $this->setUpSession();

        Sanctum::actingAs(User::factory()->create()); // not in the session
        $this->report(47.5, 19.05)->assertForbidden();
    }

    public function test_location_validation(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->seeker);
        $this->postJson("/api/sessions/{$this->sessionId}/location", ['lng' => 19.0])
            ->assertStatus(422)->assertJsonValidationErrors(['lat']);
    }

    public function test_lobby_locations_are_visible_to_everyone(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->host);
        $this->report(47.49, 19.04);

        Sanctum::actingAs($this->seeker);
        $this->assertEquals(47.49, $this->statePlayers()[$this->hostPlayerId]['lat']);
    }

    public function test_hider_location_is_hidden_from_seekers_during_seeking(): void
    {
        $this->setUpSession();

        // Host becomes the hider; progress to seeking.
        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->postJson("/api/sessions/{$this->sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $this->hostPlayerId]]);
        $this->postJson("/api/sessions/{$this->sessionId}/actions", ['type' => 'confirm_hidden']);
        $this->report(47.40, 19.00); // hider's position

        Sanctum::actingAs($this->seeker);
        $this->report(47.60, 19.20); // seeker's position

        // Seeker must NOT see the hider's coordinates, but sees their own.
        $seekerView = $this->statePlayers();
        $this->assertNull($seekerView[$this->hostPlayerId]['lat']);
        $this->assertEquals(47.60, $seekerView[$this->seekerPlayerId]['lat']);

        // The hider sees everyone.
        Sanctum::actingAs($this->host);
        $hiderView = $this->statePlayers();
        $this->assertEquals(47.40, $hiderView[$this->hostPlayerId]['lat']);
        $this->assertEquals(47.60, $hiderView[$this->seekerPlayerId]['lat']);
    }

    public function test_assign_hider_without_player_id_picks_a_random_hider(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->postJson("/api/sessions/{$this->sessionId}/actions", ['type' => 'assign_hider'])
            ->assertOk()->assertJsonPath('state', 'hiding');

        $this->assertSame(1, Session::find($this->sessionId)->players()->where('role', 'hider')->count());
    }
}
