<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\Player;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeoQuestionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{sessionId: string, hostPlayerId: string, seekerPlayerId: string, seeker: User} */
    private function setUpSeeking(): array
    {
        $host = User::factory()->create();
        Sanctum::actingAs($host);
        $create = $this->postJson('/api/sessions', ['city' => 'budapest', 'game_size' => 'small', 'config' => ['rounds' => 1]]);
        $sessionId = $create->json('id');
        $hostPlayerId = $create->json('players.0.id');

        $seeker = User::factory()->create();
        Sanctum::actingAs($seeker);
        $seekerPlayerId = $this->postJson("/api/sessions/{$create->json('join_code')}/join", ['display_name' => 'Seeker'])->json('player.id');

        Sanctum::actingAs($host);
        $this->postJson("/api/sessions/{$sessionId}/start");
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'assign_hider', 'payload' => ['player_id' => $hostPlayerId]]);
        $this->postJson("/api/sessions/{$sessionId}/actions", ['type' => 'confirm_hidden']);

        return compact('sessionId', 'hostPlayerId', 'seekerPlayerId', 'seeker');
    }

    private function radarQuestion(): Question
    {
        return Question::create([
            'key' => 'radar', 'category' => 'radar',
            'title' => ['en' => 'Radar'], 'prompt' => ['en' => 'Within R of me?'],
            'reward_draw' => 2, 'reward_keep' => 1,
        ]);
    }

    private function ask(array $ctx, int $radiusM)
    {
        Sanctum::actingAs($ctx['seeker']);

        return $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", [
            'type' => 'ask_question',
            'payload' => ['question_id' => $this->radarQuestion()->id, 'radius_m' => $radiusM],
        ]);
    }

    public function test_radar_is_answered_yes_when_hider_is_within_radius(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();

        // Hider and seeker at the same point — within any positive radius.
        Player::whereKey($ctx['hostPlayerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);
        Player::whereKey($ctx['seekerPlayerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);

        $this->ask($ctx, 1000)->assertOk();

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'yes');
    }

    public function test_radar_is_answered_no_when_hider_is_outside_radius(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();

        Player::whereKey($ctx['hostPlayerId'])->update(['last_lat' => 47.5316, 'last_lng' => 21.6273]); // Debrecen
        Player::whereKey($ctx['seekerPlayerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]); // Budapest

        $this->ask($ctx, 1000)->assertOk(); // ~200 km apart

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered' && ($e->payload['answer'] ?? null) === 'no');
    }

    public function test_radar_falls_back_to_manual_when_position_unknown(): void
    {
        Event::fake([GameEventBroadcast::class]);
        $ctx = $this->setUpSeeking();

        // Only the seeker has a position; the hider's is unknown -> not auto-evaluable.
        Player::whereKey($ctx['seekerPlayerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);

        $this->ask($ctx, 1000)->assertOk();

        Event::assertDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAsked');
        Event::assertNotDispatched(GameEventBroadcast::class, fn ($e) => $e->type === 'QuestionAnswered');
    }
}
