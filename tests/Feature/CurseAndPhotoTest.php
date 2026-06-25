<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\Curse;
use App\Models\Question;
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

        // The hider plays the curse.
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_curse', 'payload' => ['curse_id' => $curse->id]])->assertOk();

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

    public function test_a_timed_curse_carries_an_expiry(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $curse = Curse::create([
            'key' => 'timed_curse', 'name' => ['en' => 'Timed curse'], 'description' => ['en' => 'For 30 minutes…'],
            'parameters' => ['duration_s' => 1800], 'is_active' => true,
        ]);

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_curse', 'payload' => ['curse_id' => $curse->id]])->assertOk();

        $played = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('curses.0');
        $this->assertSame('active', $played['status']);
        $this->assertNotNull($played['expires_at']);
        $this->assertFalse($played['requires_proof']);
        // The seeker has nothing to upload for a purely-timed curse.
        Sanctum::actingAs($ctx['seeker']);
        $this->assertNotContains('complete_curse', $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('available_actions'));
    }
}
