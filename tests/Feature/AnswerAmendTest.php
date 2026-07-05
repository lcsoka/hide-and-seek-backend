<?php

namespace Tests\Feature;

use App\Game\Modes\HideAndSeek\HideAndSeekMode;
use App\Jobs\ComputeQuestionTruth;
use App\Models\Question;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/** Matching reference plumbing + the hider's answer-correction window. */
class AnswerAmendTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    public function test_a_manual_matching_answer_carries_the_seekers_reference(): void
    {
        $this->bindFeatures(); // no map data → the matching question is answered manually
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.51, 19.05]);

        $this->askAndAnswer($ctx, 'matching', ['answer' => 'yes', 'ref_lat' => 47.4911, 'ref_lng' => 19.1459, 'ref_name' => 'Dreher'], ['feature' => 'museum']);

        $ans = $this->lastAnswer($ctx);
        $this->assertSame('yes', $ans['answer']);
        $this->assertEqualsWithDelta(47.4911, $ans['feature_lat'], 0.001); // the reference cell anchor
        $this->assertSame('Dreher', $ans['feature_name']);
    }

    public function test_matching_shows_the_hider_their_own_nearest_but_hides_it_from_seekers(): void
    {
        $this->bindFeatures(
            $this->feature('mh', 'museum', 47.5005, 19.0405, 'Hider Museum'),  // nearest the hider
            $this->feature('ms', 'museum', 47.5105, 19.0505, 'Seeker Museum'), // nearest the seeker
        );
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.51, 19.05]);

        $question = Question::create([
            'key' => 'matching.test', 'category' => 'matching',
            'title' => ['en' => 'Matching'], 'prompt' => ['en' => 'Q'], 'parameters' => ['feature' => 'museum'],
        ]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => [
            'question_id' => $question->id, 'feature' => 'museum', 'ref_lat' => 47.5105, 'ref_lng' => 19.0505, 'ref_name' => 'Seeker Museum',
        ]])->assertOk();

        // Simulate the async truth job completing (it's deferred-after-commit in prod, so
        // it never fires under the test's rolled-back transaction).
        app(HideAndSeekMode::class)->computePendingTruth(Session::find($ctx['sessionId']), 1);

        // The hider sees their own nearest museum...
        Sanctum::actingAs($ctx['host']);
        $this->getJson("/api/v1/sessions/{$ctx['sessionId']}/state")->assertJsonPath('pending_question.hider_nearest.name', 'Hider Museum');

        // ...but a seeker never does.
        Sanctum::actingAs($ctx['seeker']);
        $this->getJson("/api/v1/sessions/{$ctx['sessionId']}/state")->assertJsonPath('pending_question.hider_nearest', null);
    }

    public function test_a_featureless_measuring_question_skips_the_truth_job_and_is_answered_manually(): void
    {
        Queue::fake();
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.51, 19.05]);

        // "International border" et al. carry no OSM point feature → not auto-computable.
        $question = Question::create([
            'key' => 'measuring.international_border', 'category' => 'measuring',
            'title' => ['en' => 'Measuring — International Border'], 'prompt' => ['en' => 'Q'], 'parameters' => null,
        ]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'ask_question', 'payload' => ['question_id' => $question->id]])->assertOk();

        // No truth job is queued (it would only fail + retry forever).
        Queue::assertNotPushed(ComputeQuestionTruth::class);

        // The hider answers it themselves — no error, recorded as manual.
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'answer_question', 'payload' => ['answer' => 'closer']])->assertOk();
        $this->assertSame('closer', $this->lastAnswer($ctx)['answer']);
    }

    public function test_hider_can_amend_a_manual_answer_within_the_window(): void
    {
        $this->bindFeatures(); // no map data → manual answer
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.51, 19.05]);
        $this->askAndAnswer($ctx, 'matching', ['answer' => 'no', 'ref_lat' => 47.49, 'ref_lng' => 19.14, 'ref_name' => 'X'], ['feature' => 'museum']);
        $this->assertSame('no', $this->lastAnswer($ctx)['answer']);

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'amend_answer', 'payload' => ['answer' => 'yes']])->assertOk();

        $this->assertSame('yes', $this->lastAnswer($ctx)['answer']);
    }

    public function test_a_server_computed_answer_cannot_be_amended(): void
    {
        // Map data present → the matching answer is the server truth (not manual).
        $this->bindFeatures(
            $this->feature('m1', 'museum', 47.50, 19.04, 'A'),
            $this->feature('m2', 'museum', 47.60, 19.20, 'B'),
        );
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.51, 19.05]);
        $this->askAndAnswer($ctx, 'matching', ['ref_lat' => 47.50, 'ref_lng' => 19.04, 'ref_name' => 'A'], ['feature' => 'museum']);

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'amend_answer', 'payload' => ['answer' => 'no']])->assertStatus(422);
    }

    public function test_amend_window_closes_after_the_configured_time(): void
    {
        $this->bindFeatures();
        $ctx = $this->startSeeking();
        $this->place($ctx, [47.50, 19.04], [47.51, 19.05]);
        $this->askAndAnswer($ctx, 'matching', ['answer' => 'no', 'ref_lat' => 47.49, 'ref_lng' => 19.14, 'ref_name' => 'X'], ['feature' => 'museum']);

        // Backdate the answer past the window.
        $session = Session::find($ctx['sessionId']);
        $sd = $session->state_data;
        $sd['questions'][array_key_last($sd['questions'])]['resolved_at'] = now()->subSeconds(200)->timestamp;
        $session->update(['state_data' => $sd]);

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'amend_answer', 'payload' => ['answer' => 'yes']])->assertStatus(422);
    }
}
