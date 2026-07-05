<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

class QuestionCooldownTest extends TestCase
{
    use RefreshDatabase;
    use SeekingScenario;

    public function test_a_category_cannot_be_re_asked_while_cooling_down(): void
    {
        $ctx = $this->startSeeking();
        $this->setCooldown($ctx['sessionId'], ['radar' => 1800]); // 30 min on radar only

        $radar = fn () => Question::create([
            'key' => 'radar.'.uniqid(), 'category' => 'radar',
            'title' => ['en' => 'Radar'], 'prompt' => ['en' => 'Q'], 'reward_draw' => 1, 'reward_keep' => 1,
        ]);

        // First radar resolves fine and starts the cooldown.
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $radar()->id, 'radius_m' => 1000]])->assertOk();
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question', 'payload' => ['answer' => 'no']])->assertOk();

        // The state now reports radar cooling down.
        Sanctum::actingAs($ctx['seeker']);
        $cooldowns = $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('question_cooldowns');
        $this->assertArrayHasKey('radar', $cooldowns);
        $this->assertGreaterThan(0, $cooldowns['radar']);

        // Re-asking radar is rejected while it cools.
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $radar()->id, 'radius_m' => 1000]])
            ->assertStatus(422);
    }

    public function test_other_categories_are_unaffected_by_a_cooldown(): void
    {
        $ctx = $this->startSeeking();
        $this->setCooldown($ctx['sessionId'], ['radar' => 1800]);

        $this->askAndAnswer($ctx, 'radar', ['radius_m' => 1000, 'answer' => 'no']);

        // Matching is not on cooldown, so it may still be asked.
        $matching = Question::create([
            'key' => 'matching.'.uniqid(), 'category' => 'matching',
            'title' => ['en' => 'Matching'], 'prompt' => ['en' => 'Q'], 'reward_draw' => 1, 'reward_keep' => 1,
        ]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $matching->id, 'ref_lat' => 47.5, 'ref_lng' => 19.0]])
            ->assertOk();
    }

    private function setCooldown(string $sessionId, array $cooldowns): void
    {
        $session = Session::find($sessionId);
        $session->update(['config' => array_merge($session->config, ['question_cooldowns' => $cooldowns])]);
    }
}
