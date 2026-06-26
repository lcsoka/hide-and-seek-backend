<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Player;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameplayTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionId;

    private string $hostPlayerId;

    private string $seekerPlayerId;

    private User $host;

    private User $seeker;

    /** Create a 2-player, single-round session; returns nothing but sets ids. */
    private function setUpSession(): void
    {
        $this->host = User::factory()->create();
        Sanctum::actingAs($this->host);
        $create = $this->postJson('/api/sessions', [
            'city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1], 'display_name' => 'Host',
        ])->assertCreated();
        $this->sessionId = $create->json('id');
        $this->hostPlayerId = $create->json('players.0.id');
        $code = $create->json('join_code');

        $this->seeker = User::factory()->create();
        Sanctum::actingAs($this->seeker);
        $this->seekerPlayerId = $this->postJson("/api/sessions/{$code}/join", ['display_name' => 'Seeker'])
            ->assertOk()->json('player.id');
    }

    private function action(string $type, array $payload = [])
    {
        return $this->postJson("/api/sessions/{$this->sessionId}/actions", ['type' => $type, 'payload' => $payload]);
    }

    public function test_full_round_progresses_lobby_to_finished(): void
    {
        $this->setUpSession();

        // Host starts -> role assignment.
        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start")->assertOk()->assertJsonPath('state', 'role_assignment');

        // Host assigns themselves as hider -> hiding.
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId])->assertOk()->assertJsonPath('state', 'hiding');

        // Hider (host) confirms hidden -> seeking.
        $this->action('confirm_hidden')->assertOk()->assertJsonPath('state', 'seeking');

        // Seeker asks a question (logged, no transition).
        Sanctum::actingAs($this->seeker);
        $this->action('ask_question', ['category' => 'radar', 'radius' => '1 mile'])
            ->assertOk()->assertJsonPath('state', 'seeking');

        // Seeker declares endgame -> endgame.
        $this->action('declare_endgame')->assertOk()->assertJsonPath('state', 'endgame');

        // Hider surrenders -> round_end.
        Sanctum::actingAs($this->host);
        $this->action('surrender')->assertOk()->assertJsonPath('state', 'round_end');

        // Host advances -> finished (single round).
        $this->action('advance_round')->assertOk()
            ->assertJsonPath('state', 'finished')
            ->assertJsonPath('status', 'finished');
    }

    public function test_reaching_the_hider_and_confirming_ends_the_round(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId]);
        $this->action('confirm_hidden');

        // Hider's spot; the seeker walks right up to it.
        Player::whereKey($this->hostPlayerId)->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);
        Player::whereKey($this->seekerPlayerId)->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);

        Sanctum::actingAs($this->seeker);
        $this->action('declare_endgame')->assertJsonPath('state', 'endgame');
        $this->action('confirm_found')->assertOk()->assertJsonPath('state', 'round_end');
    }

    public function test_non_host_cannot_start(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->seeker);
        $this->postJson("/api/sessions/{$this->sessionId}/start")
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_illegal_action_for_state_is_rejected(): void
    {
        $this->setUpSession();

        // confirm_hidden is not available in the lobby.
        Sanctum::actingAs($this->host);
        $this->action('confirm_hidden')->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_available_actions_are_player_and_state_specific(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->host);
        $this->getJson("/api/sessions/{$this->sessionId}/state")
            ->assertOk()->assertJsonPath('available_actions', ['start', 'end_game']);

        Sanctum::actingAs($this->seeker);
        $this->getJson("/api/sessions/{$this->sessionId}/state")
            ->assertOk()->assertJsonPath('available_actions', []);
    }

    public function test_state_exposes_answered_questions_and_timers_without_hider_location(): void
    {
        $this->setUpSession();

        $radar = Question::create([
            'key' => 'radar', 'category' => 'radar',
            'title' => ['hu' => 'Radar', 'en' => 'Radar'], 'prompt' => ['hu' => 'Körön belül?', 'en' => 'Within?'],
            'parameters' => ['distances' => ['1 mile']], 'reward_draw' => 2, 'reward_keep' => 1, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId]);

        // Known positions: the hider ~16 km from the seeker.
        Player::whereKey($this->hostPlayerId)->update(['last_lat' => 47.60, 'last_lng' => 19.20]);
        Player::whereKey($this->seekerPlayerId)->update(['last_lat' => 47.50, 'last_lng' => 19.04]);

        $this->action('confirm_hidden')->assertJsonPath('state', 'seeking');

        Sanctum::actingAs($this->seeker);
        $this->action('ask_question', ['question_id' => $radar->id, 'radius_m' => 1609])->assertOk();

        Sanctum::actingAs($this->host);
        $this->action('answer_question', [])->assertOk();

        Sanctum::actingAs($this->seeker);
        $state = $this->getJson("/api/sessions/{$this->sessionId}/state")->assertOk()
            ->assertJsonPath('questions.0.category', 'radar')
            ->assertJsonPath('questions.0.ask.radius_m', 1609);

        // The radar centre is the seeker's own ask-time position.
        $this->assertNotNull($state->json('questions.0.ask.lat'));
        $this->assertContains($state->json('questions.0.answer.answer'), ['yes', 'no']);
        $this->assertIsInt($state->json('timers.now'));

        // The seeker must NOT see the hider's location.
        $hider = collect($state->json('players'))->firstWhere('id', $this->hostPlayerId);
        $this->assertNull($hider['lat']);
        $this->assertNull($hider['lng']);
    }

    public function test_pending_deadline_uses_the_questions_own_answer_time(): void
    {
        $this->setUpSession();
        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId]);
        Player::whereKey($this->hostPlayerId)->update(['last_lat' => 47.50, 'last_lng' => 19.00]);
        Player::whereKey($this->seekerPlayerId)->update(['last_lat' => 47.55, 'last_lng' => 19.10]);
        $this->action('confirm_hidden');

        $q = Question::create([
            'key' => 'radar', 'category' => 'radar', 'title' => ['en' => 'Radar'], 'prompt' => ['en' => '?'],
            'reward_draw' => 1, 'reward_keep' => 1, 'answer_time_s' => 120, 'is_active' => true,
        ]);

        Sanctum::actingAs($this->seeker);
        $state = $this->action('ask_question', ['question_id' => $q->id, 'radius_m' => 5000]);
        $deadline = $state->json('pending_question.deadline');
        $now = $state->json('timers.now');
        // ~120s window (not the 600s default).
        $this->assertEqualsWithDelta(120, $deadline - $now, 5);
    }

    public function test_hider_draws_cards_by_answering_and_plays_them(): void
    {
        $this->setUpSession();
        // Deck = curses only, so the draw is deterministic for this test.
        config(['game.hider_deck.time_bonuses' => [], 'game.hider_deck.powerups' => []]);
        $radar = Question::create([
            'key' => 'radar', 'category' => 'radar', 'title' => ['hu' => 'Radar', 'en' => 'Radar'],
            'prompt' => ['hu' => '?', 'en' => '?'], 'reward_draw' => 2, 'reward_keep' => 2, 'is_active' => true,
        ]);
        foreach (['c1', 'c2', 'c3'] as $key) {
            Card::create([
                'key' => $key, 'name' => ['hu' => $key, 'en' => $key], 'cost' => ['hu' => 'x', 'en' => 'x'],
                'description' => ['hu' => 'x', 'en' => 'x'], 'is_active' => true,
            ]);
        }

        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId]); // host is the hider

        // The hand starts EMPTY (cards are earned by answering questions).
        $this->assertCount(0, $this->getJson("/api/sessions/{$this->sessionId}/state")->json('hand'));

        Player::whereKey($this->hostPlayerId)->update(['last_lat' => 47.50, 'last_lng' => 19.00]);
        Player::whereKey($this->seekerPlayerId)->update(['last_lat' => 47.55, 'last_lng' => 19.10]);
        $this->action('confirm_hidden')->assertJsonPath('state', 'seeking');

        // Seeker asks; the hider answers and draws reward_keep (2) cards.
        Sanctum::actingAs($this->seeker);
        $this->action('ask_question', ['question_id' => $radar->id, 'radius_m' => 5000]);
        $this->getJson("/api/sessions/{$this->sessionId}/state")->assertJsonPath('hand', []); // seekers never see it

        // The hider answers, draws 2 and chooses which to keep (a draw modal).
        Sanctum::actingAs($this->host);
        $this->action('answer_question');
        $draw = $this->getJson("/api/sessions/{$this->sessionId}/state")->json('pending_draw');
        $this->assertCount(2, $draw['cards']);
        $this->assertSame(2, $draw['keep']);

        $this->action('keep_cards', ['uids' => array_column($draw['cards'], 'uid')]);
        $hand = $this->getJson("/api/sessions/{$this->sessionId}/state")->json('hand');
        $this->assertCount(2, $hand);

        // Playing a card removes it (by its hand uid).
        $this->action('play_curse', ['card_uid' => $hand[0]['uid']])->assertOk();
        $this->assertCount(1, $this->getJson("/api/sessions/{$this->sessionId}/state")->json('hand'));
    }

    public function test_hider_is_locked_to_their_spot_once_seeking_starts(): void
    {
        $this->setUpSession();
        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId]); // host is the hider

        Player::whereKey($this->hostPlayerId)->update(['last_lat' => 47.50, 'last_lng' => 19.00]);

        // During hiding the hider may choose / adjust their station.
        $hiding = $this->getJson("/api/sessions/{$this->sessionId}/state");
        $this->assertContains('choose_station', $hiding->json('available_actions'));
        $this->action('choose_station', ['lat' => 47.50, 'lng' => 19.00]);
        $this->action('confirm_hidden')->assertJsonPath('state', 'seeking');

        // Once seeking begins the hider is locked — even with no seeker nearby.
        Player::whereKey($this->seekerPlayerId)->update(['last_lat' => 47.70, 'last_lng' => 19.40]);
        $seeking = $this->getJson("/api/sessions/{$this->sessionId}/state");
        $this->assertNotContains('choose_station', $seeking->json('available_actions'));
    }

    public function test_catalog_endpoints_return_active_questions_and_curses(): void
    {
        Question::create([
            'key' => 'radar', 'category' => 'radar',
            'title' => ['hu' => 'Radar', 'en' => 'Radar'], 'prompt' => ['hu' => 'x', 'en' => 'x'], 'is_active' => true,
        ]);
        Question::create([
            'key' => 'hidden', 'category' => 'radar',
            'title' => ['hu' => 'Rejtett', 'en' => 'Hidden'], 'prompt' => ['hu' => 'x', 'en' => 'x'], 'is_active' => false,
        ]);
        Card::create([
            'key' => 'luxury_car', 'name' => ['hu' => 'A luxusautó', 'en' => 'The Luxury Car'],
            'cost' => ['hu' => 'Fotó', 'en' => 'A photo'], 'description' => ['hu' => 'x', 'en' => 'x'], 'is_active' => true,
        ]);

        $this->getJson('/api/questions')->assertOk()->assertJsonCount(1)->assertJsonPath('0.category', 'radar');
        $this->getJson('/api/curses')->assertOk()->assertJsonCount(1)->assertJsonPath('0.key', 'luxury_car');
    }
}
