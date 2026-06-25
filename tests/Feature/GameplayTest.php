<?php

namespace Tests\Feature;

use App\Models\Curse;
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

    public function test_make_guess_within_radius_ends_the_round(): void
    {
        $this->setUpSession();

        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId]);
        $this->action('confirm_hidden');

        // Hider's known location (no location endpoint yet this slice).
        Player::whereKey($this->hostPlayerId)->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);

        Sanctum::actingAs($this->seeker);
        $this->action('declare_endgame')->assertJsonPath('state', 'endgame');
        $this->action('make_guess', ['lat' => 47.4979, 'lng' => 19.0402])
            ->assertOk()->assertJsonPath('state', 'round_end');
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

    public function test_hider_draws_cards_by_answering_and_plays_them(): void
    {
        $this->setUpSession();
        $radar = Question::create([
            'key' => 'radar', 'category' => 'radar', 'title' => ['hu' => 'Radar', 'en' => 'Radar'],
            'prompt' => ['hu' => '?', 'en' => '?'], 'reward_draw' => 2, 'reward_keep' => 2, 'is_active' => true,
        ]);
        foreach (['c1', 'c2', 'c3'] as $key) {
            Curse::create([
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

        Sanctum::actingAs($this->host);
        $this->action('answer_question');
        $hand = $this->getJson("/api/sessions/{$this->sessionId}/state")->json('hand');
        $this->assertCount(2, $hand);

        // Playing a card removes it.
        $this->action('play_curse', ['curse_id' => $hand[0]['curse_id']])->assertOk();
        $this->assertCount(1, $this->getJson("/api/sessions/{$this->sessionId}/state")->json('hand'));
    }

    public function test_hider_can_rehide_only_while_no_seeker_is_in_the_zone(): void
    {
        $this->setUpSession();
        Sanctum::actingAs($this->host);
        $this->postJson("/api/sessions/{$this->sessionId}/start");
        $this->action('assign_hider', ['player_id' => $this->hostPlayerId]); // host is the hider

        Player::whereKey($this->hostPlayerId)->update(['last_lat' => 47.50, 'last_lng' => 19.00]);
        $this->action('choose_station', ['lat' => 47.50, 'lng' => 19.00]);
        $this->action('confirm_hidden')->assertJsonPath('state', 'seeking');

        // Seeker far away → the hider may move to another station.
        Player::whereKey($this->seekerPlayerId)->update(['last_lat' => 47.70, 'last_lng' => 19.40]);
        $state = $this->getJson("/api/sessions/{$this->sessionId}/state");
        $this->assertContains('choose_station', $state->json('available_actions'));
        $state->assertJsonPath('zone_locked', false);
        $this->action('choose_station', ['lat' => 47.51, 'lng' => 19.01])->assertOk();

        // Seeker enters the (new) zone → re-hiding is locked.
        Player::whereKey($this->seekerPlayerId)->update(['last_lat' => 47.51, 'last_lng' => 19.01]);
        $state = $this->getJson("/api/sessions/{$this->sessionId}/state");
        $this->assertNotContains('choose_station', $state->json('available_actions'));
        $state->assertJsonPath('zone_locked', true);
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
        Curse::create([
            'key' => 'luxury_car', 'name' => ['hu' => 'A luxusautó', 'en' => 'The Luxury Car'],
            'cost' => ['hu' => 'Fotó', 'en' => 'A photo'], 'description' => ['hu' => 'x', 'en' => 'x'], 'is_active' => true,
        ]);

        $this->getJson('/api/questions')->assertOk()->assertJsonCount(1)->assertJsonPath('0.category', 'radar');
        $this->getJson('/api/curses')->assertOk()->assertJsonCount(1)->assertJsonPath('0.key', 'luxury_car');
    }
}
