<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentTest extends TestCase
{
    use RefreshDatabase;

    private function createSession(): string
    {
        return $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]])->json('id');
    }

    public function test_registered_user_authors_and_lists_custom_curses(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/my/curses', ['name' => 'The Snail', 'cost' => 'Walk only', 'description' => 'No transit for 10 min', 'duration_minutes' => 10, 'requires_proof' => true])
            ->assertCreated()->assertJsonPath('name', 'The Snail')->assertJsonPath('duration_minutes', 10)->assertJsonPath('requires_proof', true);

        $this->getJson('/api/my/content')->assertOk()->assertJsonPath('curses.0.name', 'The Snail');
    }

    public function test_guests_cannot_author(): void
    {
        // A guest = user with no email.
        Sanctum::actingAs(User::create(['name' => 'Guest']));

        $this->postJson('/api/my/curses', ['name' => 'X'])->assertStatus(403);
    }

    public function test_a_user_cannot_edit_or_delete_another_users_content(): void
    {
        Sanctum::actingAs($author = User::factory()->create());
        $id = $this->postJson('/api/my/curses', ['name' => 'Mine'])->json('id');

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/my/curses/{$id}", ['name' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/my/curses/{$id}")->assertStatus(403);
    }

    public function test_delete_own_curse(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $id = $this->postJson('/api/my/curses', ['name' => 'Temp'])->json('id');

        $this->deleteJson("/api/my/curses/{$id}")->assertOk();
        $this->assertDatabaseMissing('cards', ['id' => $id]);
    }

    public function test_host_custom_curse_is_scoped_to_their_deck(): void
    {
        Sanctum::actingAs($host = User::factory()->create());
        $curseId = $this->postJson('/api/my/curses', ['name' => 'Host Curse'])->json('id');
        $sessionId = $this->createSession();

        // The host's own game stores their user id (so deckPool includes their custom curses).
        $this->assertDatabaseHas('game_sessions', ['id' => $sessionId]);
        $this->assertSame($host->id, \App\Models\Session::find($sessionId)->state_data['host_user_id']);
        // The custom curse belongs to the host and is a curse card.
        $this->assertDatabaseHas('cards', ['id' => $curseId, 'user_id' => $host->id, 'type' => 'curse', 'is_custom' => true]);
    }

    public function test_custom_questions_appear_only_in_the_authors_game_catalog(): void
    {
        Sanctum::actingAs($host = User::factory()->create());
        $questionId = $this->postJson('/api/my/questions', ['title' => 'Red door', 'prompt' => 'Send a photo of a red door.'])->json('id');
        $ownSession = $this->createSession();

        $ownCatalog = collect($this->getJson("/api/sessions/{$ownSession}/questions")->json())->pluck('id');
        $this->assertTrue($ownCatalog->contains($questionId));

        // A different host's game must not include it.
        Sanctum::actingAs(User::factory()->create());
        $otherSession = $this->createSession();
        $otherCatalog = collect($this->getJson("/api/sessions/{$otherSession}/questions")->json())->pluck('id');
        $this->assertFalse($otherCatalog->contains($questionId));
    }
}
