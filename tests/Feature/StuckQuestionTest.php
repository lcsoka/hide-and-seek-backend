<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

class StuckQuestionTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    private function radarQuestion(string $key): Question
    {
        return Question::create([
            'key' => $key, 'category' => 'radar', 'title' => ['en' => 'R'], 'prompt' => ['en' => '?'],
            'reward_draw' => 1, 'reward_keep' => 1,
        ]);
    }

    public function test_a_stale_pending_question_does_not_deadlock_new_asks(): void
    {
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);
        $sid = $ctx['sessionId'];

        // Simulate a question that got stuck: its answer window elapsed long ago and it never resolved
        // (e.g. Overpass was down so the resolve timer/job never cleared it).
        $session = Session::find($sid);
        $session->update(['state_data' => array_merge($session->state_data, [
            'pending_question' => [
                'seq' => 1, 'question_id' => null, 'category' => 'radar', 'asked_by' => $ctx['seekerId'],
                'payload' => [], 'asked_at' => now()->subMinutes(20)->timestamp,
                'deadline' => now()->subMinutes(10)->timestamp, 'truth' => null,
            ],
            'question_seq' => 1,
            'question_answer' => 1,
        ])]);

        // Before the fix this 422'd forever ("A question is already awaiting an answer").
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$sid}/actions", [
            'type' => 'ask_question', 'payload' => ['question_id' => $this->radarQuestion('radar.unstick')->id, 'radius_m' => 5000],
        ])->assertOk();

        // The new question is now pending; the stale one is gone.
        $this->assertSame(2, Session::find($sid)->state_data['pending_question']['seq']);
    }

    public function test_an_overdue_pending_question_resolves_itself_on_a_state_read(): void
    {
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);
        $sid = $ctx['sessionId'];

        // A pending question whose answer window elapsed, with its queued timer never fired.
        $session = Session::find($sid);
        $session->update(['state_data' => array_merge($session->state_data, [
            'pending_question' => [
                'seq' => 1, 'question_id' => null, 'category' => 'radar', 'asked_by' => $ctx['seekerId'],
                'payload' => [], 'asked_at' => now()->subMinutes(20)->timestamp,
                'deadline' => now()->subMinutes(10)->timestamp, 'truth' => null,
            ],
            'question_seq' => 1,
            'question_answer' => 1,
        ])]);

        // Simply reading state (no new ask) resolves the overdue question — it disappears.
        Sanctum::actingAs($ctx['seeker']);
        $res = $this->getJson("/api/v1/sessions/{$sid}/state")->assertOk();
        $this->assertNull($res->json('pending_question'));
        $this->assertNull(Session::find($sid)->state_data['pending_question']);
    }

    public function test_a_fresh_pending_question_still_blocks_a_second_ask(): void
    {
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.55, 19.10]);
        $sid = $ctx['sessionId'];
        $q = $this->radarQuestion('radar.fresh');

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$sid}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id, 'radius_m' => 5000]])->assertOk();
        // A second ask while the first is still within its window → correctly rejected.
        $this->postJson("/api/v1/sessions/{$sid}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $q->id, 'radius_m' => 5000]])->assertStatus(422);
    }
}
