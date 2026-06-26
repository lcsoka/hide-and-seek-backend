<?php

namespace Tests\Feature;

use App\Models\Card;
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
        // Deck = curses only (no powerups/time-bonuses), so every drawn card is a playable
        // curse. Seed enough copies: the depleting deck never recycles, and the round draws
        // 2 per answer across two questions.
        for ($i = 1; $i <= 8; $i++) {
            Card::create([
                'key' => "c{$i}", 'name' => ['hu' => 'Átok', 'en' => 'Curse'], 'cost' => ['hu' => 'x', 'en' => 'x'],
                'description' => ['hu' => 'x', 'en' => 'x'], 'is_active' => true,
            ]);
        }
        config(['game.hider_deck.time_bonuses' => [], 'game.hider_deck.powerups' => []]);

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
        $this->assertCount(0, $this->getJson("/api/sessions/{$sid}/state")->json('hand'), 'hider starts empty-handed');

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

        // Hider answers (radar truth: ~8 km apart, 5 km radius → "no"), draws 2, keeps 1.
        Sanctum::actingAs($host);
        $this->action($sid, 'answer_question')->assertOk();
        $draw = $this->getJson("/api/sessions/{$sid}/state")->json('pending_draw');
        $this->assertCount(2, $draw['cards'], 'hider drew reward_draw cards');
        $this->action($sid, 'keep_cards', ['uids' => [$draw['cards'][0]['uid']]])->assertOk();
        $this->assertCount(1, $this->getJson("/api/sessions/{$sid}/state")->json('hand'), 'hider kept reward_keep card');

        // THE REGRESSION GUARD: the seeker can ask again after the answer, and sees it.
        Sanctum::actingAs($seeker);
        $state = $this->getJson("/api/sessions/{$sid}/state");
        $this->assertContains('ask_question', $state->json('available_actions'));
        $this->assertSame('no', $state->json('questions.0.answer.answer'));
        $this->action($sid, 'ask_question', ['question_id' => $radar->id, 'radius_m' => 50000])->assertOk();

        // Hider plays a curse from hand (by uid), then answers.
        Sanctum::actingAs($host);
        $cardUid = $this->getJson("/api/sessions/{$sid}/state")->json('hand.0.uid');
        $this->action($sid, 'play_curse', ['card_uid' => $cardUid])->assertOk();
        $this->action($sid, 'answer_question')->assertOk();

        // Seeker reaches the hider's spot and confirms the catch.
        Sanctum::actingAs($seeker);
        Player::whereKey($seekerPid)->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);
        $this->action($sid, 'declare_endgame')->assertJsonPath('state', 'endgame');
        $this->action($sid, 'confirm_found')->assertOk()->assertJsonPath('state', 'round_end');

        // Host advances → finished (single round).
        Sanctum::actingAs($host);
        $this->action($sid, 'advance_round')->assertOk()->assertJsonPath('state', 'finished')->assertJsonPath('status', 'finished');
    }

    public function test_second_round_resets_question_numbers_and_recomputes_zone_for_the_new_hider(): void
    {
        $radar = Question::create([
            'key' => 'radar', 'category' => 'radar', 'title' => ['en' => 'Radar'], 'prompt' => ['en' => '?'],
            'reward_draw' => 2, 'reward_keep' => 1, 'is_active' => true,
        ]);
        for ($i = 1; $i <= 8; $i++) {
            Card::create(['key' => "c{$i}", 'name' => ['en' => 'Curse'], 'cost' => ['en' => 'x'], 'description' => ['en' => 'x'], 'is_active' => true]);
        }
        config(['game.hider_deck.time_bonuses' => [], 'game.hider_deck.powerups' => []]);

        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 2], 'display_name' => 'Al'])->assertCreated();
        $sid = $create->json('id');
        $hostPid = $create->json('players.0.id');
        $seeker = User::factory()->create();
        Sanctum::actingAs($seeker);
        $seekerPid = $this->postJson("/api/sessions/{$create->json('join_code')}/join", ['display_name' => 'Bo'])->json('player.id');

        // --- ROUND 1: the HOST hides at spot A ---
        Sanctum::actingAs($host);
        $this->postJson("/api/sessions/{$sid}/start");
        $this->action($sid, 'assign_hider', ['player_id' => $hostPid])->assertJsonPath('state', 'hiding');
        Player::whereKey($hostPid)->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);
        Player::whereKey($seekerPid)->update(['last_lat' => 47.55, 'last_lng' => 19.10]);
        $this->action($sid, 'choose_station', ['lat' => 47.4979, 'lng' => 19.0402]);
        // The hider's zone is centred on spot A.
        $this->assertEqualsWithDelta(47.4979, $this->getJson("/api/sessions/{$sid}/state")->json('hiding_zone.center.lat'), 1e-6);
        $this->action($sid, 'confirm_hidden')->assertJsonPath('state', 'seeking');

        Sanctum::actingAs($seeker);
        $this->action($sid, 'ask_question', ['question_id' => $radar->id, 'radius_m' => 5000]);
        Sanctum::actingAs($host);
        $this->action($sid, 'answer_question');
        $this->assertSame(1, $this->getJson("/api/sessions/{$sid}/state")->json('questions.0.seq'), 'round 1 first question is #1');
        $draw = $this->getJson("/api/sessions/{$sid}/state")->json('pending_draw');
        $this->action($sid, 'keep_cards', ['uids' => [$draw['cards'][0]['uid']]]);
        Sanctum::actingAs($seeker);
        Player::whereKey($seekerPid)->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);
        $this->action($sid, 'declare_endgame')->assertJsonPath('state', 'endgame');
        $this->action($sid, 'confirm_found')->assertJsonPath('state', 'round_end');

        // --- ADVANCE to round 2 (rounds=2, so not finished) ---
        Sanctum::actingAs($host);
        $this->action($sid, 'advance_round')->assertOk()->assertJsonPath('state', 'role_assignment');

        // --- ROUND 2: the OTHER player (Bo) hides at a DIFFERENT spot B ---
        $this->action($sid, 'assign_hider', ['player_id' => $seekerPid])->assertJsonPath('state', 'hiding');
        Sanctum::actingAs($seeker); // Bo is now the hider
        Player::whereKey($seekerPid)->update(['last_lat' => 47.5100, 'last_lng' => 19.0700]);
        $this->action($sid, 'choose_station', ['lat' => 47.5100, 'lng' => 19.0700]);
        // The zone is recomputed for the NEW hider's spot B (not the old spot A).
        $zone = $this->getJson("/api/sessions/{$sid}/state")->json('hiding_zone.center');
        $this->assertEqualsWithDelta(47.5100, $zone['lat'], 1e-6, 'round 2 zone is for the new hider');
        $this->assertEqualsWithDelta(19.0700, $zone['lng'], 1e-6);
        $this->action($sid, 'confirm_hidden')->assertJsonPath('state', 'seeking');

        // The host is now the seeker; their first question is numbered #1 again (seq reset).
        Sanctum::actingAs($host);
        Player::whereKey($hostPid)->update(['last_lat' => 47.40, 'last_lng' => 19.00]);
        $this->action($sid, 'ask_question', ['question_id' => $radar->id, 'radius_m' => 5000]);
        Sanctum::actingAs($seeker); // Bo (hider) answers
        $this->action($sid, 'answer_question');
        $questions = $this->getJson("/api/sessions/{$sid}/state")->json('questions');
        $this->assertCount(1, $questions, 'round 2 starts with no carried-over questions');
        $this->assertSame(1, $questions[0]['seq'], 'question numbering resets each round');
    }

    private function action(string $sid, string $type, array $payload = [])
    {
        return $this->postJson("/api/sessions/{$sid}/actions", ['type' => $type, 'payload' => $payload]);
    }
}
