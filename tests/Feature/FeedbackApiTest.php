<?php

namespace Tests\Feature;

use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_submit_a_suggestion(): void
    {
        $response = $this->postJson('/api/feedback', [
            'type' => 'suggestion',
            'subject' => 'Dark mode',
            'message' => 'Please add a dark mode to the map.',
        ]);

        $response->assertCreated()->assertJsonStructure(['id', 'status']);
        $this->assertDatabaseHas('feedback', [
            'type' => 'suggestion',
            'subject' => 'Dark mode',
            'status' => 'open',
        ]);
    }

    public function test_a_bug_report_can_carry_session_context(): void
    {
        $session = Session::create([
            'join_code' => 'FBK001', 'game_mode' => 'hide_and_seek',
            'state' => 'lobby', 'status' => 'open',
        ]);
        $player = $session->players()->create(['display_name' => 'Reporter']);

        $response = $this->postJson('/api/feedback', [
            'type' => 'bug',
            'message' => 'Map froze during seeking.',
            'session_id' => $session->id,
            'player_id' => $player->id,
            'context' => ['url' => '/s/FBK001', 'app_version' => '0.1.0'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('feedback', [
            'type' => 'bug',
            'session_id' => $session->id,
            'player_id' => $player->id,
        ]);
    }

    public function test_type_and_message_are_required(): void
    {
        $this->postJson('/api/feedback', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'message']);
    }

    public function test_type_must_be_a_valid_enum_value(): void
    {
        $this->postJson('/api/feedback', ['type' => 'rant', 'message' => 'hi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_context_ids_must_exist(): void
    {
        $this->postJson('/api/feedback', [
            'type' => 'bug',
            'message' => 'x',
            'session_id' => '019ef981-0000-7000-8000-000000000000',
        ])->assertStatus(422)->assertJsonValidationErrors(['session_id']);
    }
}
