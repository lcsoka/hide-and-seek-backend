<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Game\GameEngine;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeoQuestionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{sessionId: string, hostPlayerId: string, seekerPlayerId: string, host: User, seeker: User} */
    private function setUpSeeking(): array
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/v1/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hostPlayerId = $create->json('players.0.id');

        $seeker = User::factory()->create();
        Sanctum::actingAs($seeker);
        $seekerPlayerId = $this->postJson("/api/v1/sessions/{$create->json('join_code')}/join", ['display_name' => 'Seeker'])->json('player.id');

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/sessions/{$sessionId}/start");
        $this->postJson("/api/v1/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hostPlayerId]]);
        $this->postJson("/api/v1/sessions/{$sessionId}/actions", ['type' => 'confirm_hidden']);

        return compact('sessionId', 'hostPlayerId', 'seekerPlayerId', 'host', 'seeker');
    }

    private function radar(): Question
    {
        return Question::create([
            'key' => 'radar', 'category' => 'radar',
            'title' => ['en' => 'Radar'], 'prompt' => ['en' => 'Within R?'],
            'reward_draw' => 2, 'reward_keep' => 1,
        ]);
    }

    private function placeBoth(array $ctx, array $hider, array $seeker): void
    {
        Player::whereKey($ctx['hostPlayerId'])->update(['last_lat' => $hider[0], 'last_lng' => $hider[1]]);
        Player::whereKey($ctx['seekerPlayerId'])->update(['last_lat' => $seeker[0], 'last_lng' => $seeker[1]]);
    }

    private function ask(array $ctx, string $questionId, int $radiusM)
    {
        Sanctum::actingAs($ctx['seeker']);

        return $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", [
            'type' => 'ask_question', 'payload' => ['question_id' => $questionId, 'radius_m' => $radiusM],
        ]);
    }

    private function answer(array $ctx, mixed $value = null)
    {
        Sanctum::actingAs($ctx['host']); // host is the hider

        return $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", [
            'type' => 'answer_question', 'payload' => $value === null ? [] : ['answer' => $value],
        ]);
    }

    public function test_question_stays_pending_until_the_hider_answers(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.4979, 19.0402], [47.4979, 19.0402]); // same point
        $q = $this->radar();

        $this->ask($ctx, $q->id, 1000)->assertOk();
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAsked');
        Event::assertNotDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered');

        $this->answer($ctx)->assertOk();
        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'yes');
    }

    public function test_radar_truth_is_no_when_outside_radius(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.5316, 21.6273], [47.4979, 19.0402]); // ~200 km apart
        $q = $this->radar();

        $this->ask($ctx, $q->id, 1000)->assertOk();
        $this->answer($ctx)->assertOk();

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'no');
    }

    public function test_deadline_auto_resolves_with_the_server_truth(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.4979, 19.0402], [47.4979, 19.0402]);
        $q = $this->radar();

        $this->ask($ctx, $q->id, 1000)->assertOk();

        $session = Session::find($ctx['sessionId']);
        app(GameEngine::class)->fireTimer($session, 'question_answer', $session->state_data['question_answer']);

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered'
            && ($e->payload['auto'] ?? false) === true && ($e->payload['answer'] ?? null) === 'yes');
    }

    public function test_seeker_cannot_ask_while_a_question_is_pending(): void
    {
        $ctx = $this->setUpSeeking();
        $this->placeBoth($ctx, [47.4979, 19.0402], [47.4979, 19.0402]);
        $q = $this->radar();

        $this->ask($ctx, $q->id, 1000)->assertOk();
        $this->ask($ctx, $q->id, 1000)->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_manual_answer_when_category_has_no_evaluator(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();
        $photo = Question::create([
            'key' => 'photo.tree', 'category' => 'photo',
            'title' => ['en' => 'Photo'], 'prompt' => ['en' => 'A tree'],
            'reward_draw' => 1, 'reward_keep' => 1,
        ]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $photo->id]])->assertOk();

        $this->answer($ctx, 'a photo of a tree')->assertOk();

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'a photo of a tree');
    }
}
