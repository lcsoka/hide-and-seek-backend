<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\Curse;
use App\Models\Question;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CurseAndPhotoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{sessionId: string, hiderId: string, host: User, seeker: User} */
    private function setUpSeeking(): array
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hiderId = $create->json('players.0.id');

        $seeker = User::factory()->create();
        Sanctum::actingAs($seeker);
        $this->postJson("/api/sessions/{$create->json('join_code')}/join", ['display_name' => 'Seeker']);

        Sanctum::actingAs($host);
        $this->postJson("/api/sessions/{$sessionId}/start");
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hiderId]]);
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'confirm_hidden']);

        return compact('sessionId', 'hiderId', 'host', 'seeker');
    }

    private function giveHiderCard(string $sessionId, array $card): void
    {
        $session = Session::find($sessionId);
        $data = $session->state_data;
        $data['hand'] = array_merge($data['hand'] ?? [], [$card]);
        $session->update(['state_data' => $data]);
    }

    public function test_pending_preview_only_shows_the_precomputed_truth(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $session = Session::find($ctx['sessionId']);
        $base = ['seq' => 1, 'question_id' => null, 'category' => 'matching', 'asked_by' => $ctx['hiderId'], 'payload' => ['feature' => 'museum'], 'deadline' => now()->addMinutes(5)->timestamp];

        // Pre-computed truth → shown to the hider.
        $session->update(['state_data' => array_merge($session->state_data, ['pending_question' => $base + ['truth' => ['answer' => 'yes']]])]);
        Sanctum::actingAs($ctx['host']);
        $this->assertSame('yes', $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('pending_question.preview_answer.answer'));

        // No truth yet → null. The /state read path must never evaluate it inline
        // (that previously hit Overpass synchronously and timed the request out).
        $session->update(['state_data' => array_merge($session->state_data, ['pending_question' => $base + ['truth' => null]])]);
        $this->assertNull($this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('pending_question.preview_answer'));
    }

    public function test_seeker_movement_broadcasts_but_the_hiders_does_not(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();

        // A seeker moving is broadcast (others, incl. the hider, see it).
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.50, 'lng' => 19.05])->assertNoContent();
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'PlayerMoved');

        // The hider's position is concealed — never broadcast.
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.49, 'lng' => 19.04])->assertNoContent();
        Event::assertNotDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'PlayerMoved' && ($e->payload['player_id'] ?? null) === $ctx['hiderId']);
    }

    public function test_a_player_can_upload_an_image_to_their_session(): void
    {
        Event::fake([GameEventBroadcast::class]);
        Storage::fake('public');
        $ctx = $this->setUpSeeking();

        Sanctum::actingAs($ctx['seeker']);
        $res = $this->post("/api/sessions/{$ctx['sessionId']}/media", ['image' => UploadedFile::fake()->image('clue.jpg')]);

        $res->assertOk()->assertJsonStructure(['path', 'url']);
        Storage::disk('public')->assertExists($res->json('path'));
    }

    public function test_photo_question_is_answered_with_an_image_visible_to_the_seeker(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $question = Question::create([
            'key' => 'photo.selfie', 'category' => 'photo',
            'title' => ['en' => 'Selfie'], 'prompt' => ['en' => 'Send a selfie'], 'reward_draw' => 1, 'reward_keep' => 1,
        ]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $question->id]])->assertOk();

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", [
            'type' => 'answer_question', 'payload' => ['photo_url' => 'http://localhost/storage/media/x/photo.jpg'],
        ])->assertOk();

        Sanctum::actingAs($ctx['seeker']);
        $answer = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('questions.0.answer');
        $this->assertSame('photo', $answer['answer']);
        $this->assertSame('http://localhost/storage/media/x/photo.jpg', $answer['photo_url']);
    }

    public function test_a_curse_requiring_proof_is_cleared_by_a_seeker_uploaded_photo(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $curse = Curse::create([
            'key' => 'proof_curse', 'name' => ['en' => 'Proof curse'], 'description' => ['en' => 'Photograph a car'],
            'parameters' => ['requires_proof' => true], 'is_active' => true,
        ]);

        // Put the curse card in the hider's hand, then play it.
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'h1', 'type' => 'curse', 'curse_id' => $curse->id]);
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_curse', 'payload' => ['card_uid' => 'h1']])->assertOk();

        // The seeker now sees an active proof-curse and may clear it.
        Sanctum::actingAs($ctx['seeker']);
        $state = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json();
        $this->assertContains('complete_curse', $state['available_actions']);
        $this->assertTrue($state['curses'][0]['requires_proof']);
        $this->assertSame('active', $state['curses'][0]['status']);
        $uid = $state['curses'][0]['uid'];

        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", [
            'type' => 'complete_curse', 'payload' => ['curse_uid' => $uid, 'proof_url' => 'http://localhost/storage/media/x/proof.jpg'],
        ])->assertOk();

        $curses = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('curses');
        $this->assertSame('completed', $curses[0]['status']);
        $this->assertSame('http://localhost/storage/media/x/proof.jpg', $curses[0]['proof_url']);
    }

    public function test_time_bonus_cards_in_hand_add_to_the_hiders_banked_time(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 't1', 'type' => 'time_bonus', 'minutes' => 10]);
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 't2', 'type' => 'time_bonus', 'minutes' => 5]);

        Sanctum::actingAs($ctx['host']);
        $this->assertSame(900, $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('time_bonus_s'));
    }

    public function test_veto_powerup_discards_the_pending_question(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $question = Question::create([
            'key' => 'radar', 'category' => 'radar', 'title' => ['en' => 'Radar'], 'prompt' => ['en' => '?'],
            'reward_draw' => 1, 'reward_keep' => 1, 'is_active' => true,
        ]);
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'v1', 'type' => 'powerup', 'power' => 'veto']);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $question->id, 'radius_m' => 5000]])->assertOk();

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_powerup', 'payload' => ['card_uid' => 'v1']])->assertOk();

        // The question is gone (no answer recorded) and the seeker can ask again.
        Sanctum::actingAs($ctx['seeker']);
        $state = $this->getJson("/api/sessions/{$ctx['sessionId']}/state");
        $this->assertNull($state->json('pending_question'));
        $this->assertCount(0, $state->json('questions'));
        $this->assertContains('ask_question', $state->json('available_actions'));
    }

    public function test_a_dice_curse_can_be_rolled_by_a_seeker(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $curse = Curse::create([
            'key' => 'dice_curse', 'name' => ['en' => 'Jammed Door'], 'description' => ['en' => 'Roll 7+ to enter'],
            'parameters' => ['dice' => ['count' => 2, 'sides' => 6, 'target' => 7]], 'is_active' => true,
        ]);
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'h1', 'type' => 'curse', 'curse_id' => $curse->id]);

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_curse', 'payload' => ['card_uid' => 'h1']])->assertOk();

        // The seeker sees the dice spec and may roll.
        Sanctum::actingAs($ctx['seeker']);
        $state = $this->getJson("/api/sessions/{$ctx['sessionId']}/state");
        $this->assertContains('roll_dice', $state->json('available_actions'));
        $uid = $state->json('curses.0.uid');
        $this->assertSame(2, $state->json('curses.0.dice.count'));

        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'roll_dice', 'payload' => ['curse_uid' => $uid]])->assertOk();

        $roll = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('curses.0.last_roll');
        $this->assertCount(2, $roll['values']);
        $this->assertSame(array_sum($roll['values']), $roll['sum']);
        $this->assertIsBool($roll['success']);
    }

    public function test_a_timed_curse_carries_an_expiry(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $curse = Curse::create([
            'key' => 'timed_curse', 'name' => ['en' => 'Timed curse'], 'description' => ['en' => 'For 30 minutes…'],
            'parameters' => ['duration_s' => 1800], 'is_active' => true,
        ]);

        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'h1', 'type' => 'curse', 'curse_id' => $curse->id]);
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_curse', 'payload' => ['card_uid' => 'h1']])->assertOk();

        $played = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('curses.0');
        $this->assertSame('active', $played['status']);
        $this->assertNotNull($played['expires_at']);
        $this->assertFalse($played['requires_proof']);
        // The seeker has nothing to upload for a purely-timed curse.
        Sanctum::actingAs($ctx['seeker']);
        $this->assertNotContains('complete_curse', $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('available_actions'));
    }
}
