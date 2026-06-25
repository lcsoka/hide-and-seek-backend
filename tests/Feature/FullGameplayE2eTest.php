<?php

namespace Tests\Feature;

use App\Models\Curse;
use App\Models\Player;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end gameplay over the real HTTP API: lobby → role assignment → hiding →
 * seeking (ask/answer loop, curse cards) → endgame → guess → finished. Guards the
 * full loop, including a seeker asking a SECOND question after the hider answers.
 */
class FullGameplayE2eTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_game_lobby_to_finished_with_questions_and_curses(): void
    {
        $radar = Question::create([
            'key' => 'radar', 'category' => 'radar',
            'title' => ['hu' => 'Radar', 'en' => 'Radar'], 'prompt' => ['hu' => '?', 'en' => '?'],
            'reward_draw' => 2, 'reward_keep' => 1, 'is_active' => true,
        ]);
        Curse::create([
            'key' => 'c1', 'name' => ['hu' => 'Átok', 'en' => 'Curse'], 'cost' => ['hu' => 'x', 'en' => 'x'],
            'description' => ['hu' => 'x', 'en' => 'x'], 'is_active' => true,
        ]);

        // Host creates; a second player joins.
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1], 'display_name' => 'Al'])->assertCreated();
        $sid = $create->json('id');
        $hostPid = $create->json('players.0.id');

        $seeker = User::factory()->create();
        Sanctum::actingAs($seeker);
        $seekerPid = $this->postJson("/api/sessions/{$create->json('join_code')}/join", ['display_name' => 'Bo'])->assertOk()->json('player.id');

        // Host starts and makes themselves the hider (so Bo is the seeker).
        Sanctum::actingAs($host);
        $this->postJson("/api/sessions/{$sid}/start")->assertJsonPath('state', 'role_assignment');
        $this->action($sid, 'assign_hider', ['player_id' => $hostPid])->assertJsonPath('state', 'hiding');
        $this->assertCount(3, $this->getJson("/api/sessions/{$sid}/state")->json('hand'), 'hider starts with 3 cards');

        // Real positions: ~8 km apart.
        Player::whereKey($hostPid)->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);
        Player::whereKey($seekerPid)->update(['last_lat' => 47.55, 'last_lng' => 19.10]);

        $this->action($sid, 'confirm_hidden')->assertJsonPath('state', 'seeking');

        // Seeker asks a radar question.
        Sanctum::actingAs($seeker);
        $this->assertContains('ask_question', $this->getJson("/api/sessions/{$sid}/state")->json('available_actions'));
        $this->action($sid, 'ask_question', ['question_id' => $radar->id, 'radius_m' => 5000])->assertOk();
        // While pending, the seeker cannot ask another.
        $this->assertNotContains('ask_question', $this->getJson("/api/sessions/{$sid}/state")->json('available_actions'));

        // Hider answers (radar truth: ~8 km apart, 5 km radius → "no") and draws a card.
        Sanctum::actingAs($host);
        $this->action($sid, 'answer_question')->assertOk();
        $this->assertCount(4, $this->getJson("/api/sessions/{$sid}/state")->json('hand'), 'hider drew reward_keep card');

        // THE REGRESSION GUARD: the seeker can ask again after the answer, and sees it.
        Sanctum::actingAs($seeker);
        $state = $this->getJson("/api/sessions/{$sid}/state");
        $this->assertContains('ask_question', $state->json('available_actions'));
        $this->assertSame('no', $state->json('questions.0.answer.answer'));
        $this->action($sid, 'ask_question', ['question_id' => $radar->id, 'radius_m' => 50000])->assertOk();

        // Hider plays a curse from hand, then answers.
        Sanctum::actingAs($host);
        $card = $this->getJson("/api/sessions/{$sid}/state")->json('hand.0.curse_id');
        $this->action($sid, 'play_curse', ['curse_id' => $card])->assertOk();
        $this->action($sid, 'answer_question')->assertOk();

        // Seeker declares endgame and guesses correctly at the hider's spot.
        Sanctum::actingAs($seeker);
        $this->action($sid, 'declare_endgame')->assertJsonPath('state', 'endgame');
        $this->action($sid, 'make_guess', ['lat' => 47.4979, 'lng' => 19.0402])->assertJsonPath('state', 'round_end');

        // Host advances → finished (single round).
        Sanctum::actingAs($host);
        $this->action($sid, 'advance_round')->assertOk()->assertJsonPath('state', 'finished')->assertJsonPath('status', 'finished');
    }

    private function action(string $sid, string $type, array $payload = [])
    {
        return $this->postJson("/api/sessions/{$sid}/actions", ['type' => $type, 'payload' => $payload]);
    }
}
