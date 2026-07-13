<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\Card;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/** Safety nets against a jammed game: cancelling a stuck question + clearing a curse mid-thermometer. */
class StuckStateTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([GameEventBroadcast::class]);
    }

    /** @return array<string, mixed> */
    private function stateAs(array $ctx, User $user): array
    {
        Sanctum::actingAs($user);

        return $this->getJson("/api/v1/sessions/{$ctx['sessionId']}/state")->json();
    }

    private function injectPending(array $ctx): void
    {
        $s = Session::find($ctx['sessionId']);
        $data = $s->state_data;
        $data['pending_question'] = ['seq' => 1, 'asked_by' => $ctx['seekerId'], 'question_id' => 'q', 'deadline' => now()->timestamp + 600, 'payload' => []];
        $s->update(['state_data' => $data]);
    }

    public function test_the_asker_can_cancel_a_stuck_pending_question(): void
    {
        $ctx = $this->startSeeking();
        $this->injectPending($ctx);

        $this->assertContains('cancel_question', $this->stateAs($ctx, $ctx['seeker'])['available_actions']);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'cancel_question'])->assertOk();

        $state = $this->stateAs($ctx, $ctx['seeker']);
        $this->assertNull($state['pending_question']);
        $this->assertContains('ask_question', $state['available_actions'], 'the seeker is free to ask again');
    }

    public function test_the_host_can_cancel_a_stuck_pending_question(): void
    {
        $ctx = $this->startSeeking(); // the host is the hider here
        $this->injectPending($ctx);

        $this->assertContains('cancel_question', $this->stateAs($ctx, $ctx['host'])['available_actions']);
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'cancel_question'])->assertOk();

        $this->assertNull($this->stateAs($ctx, $ctx['host'])['pending_question']);
    }

    public function test_an_unrelated_seeker_cannot_cancel_and_there_is_nothing_to_cancel_when_idle(): void
    {
        $ctx = $this->startSeeking();
        // No pending question → the action isn't offered and is rejected.
        $this->assertNotContains('cancel_question', $this->stateAs($ctx, $ctx['seeker'])['available_actions']);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'cancel_question'])->assertStatus(422);
    }

    public function test_a_seeker_can_clear_a_curse_while_a_thermometer_is_running(): void
    {
        $ctx = $this->startSeeking();
        $curse = Card::create([
            'key' => 'proof_curse_therm', 'name' => ['en' => 'Proof'], 'description' => ['en' => 'Photograph something'],
            'effect' => ['requires_proof' => true], 'is_active' => true,
        ]);
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'c1', 'type' => 'curse', 'curse_id' => $curse->id]);
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/v1/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_curse', 'payload' => ['card_uid' => 'c1']])->assertOk();

        // The seeker is mid-thermometer.
        $s = Session::find($ctx['sessionId']);
        $data = $s->state_data;
        $data['thermometer'] = ['asked_by' => $ctx['seekerId'], 'start_lat' => 47.5, 'start_lng' => 19.0, 'distance_m' => 100];
        $s->update(['state_data' => $data]);

        // They can STILL clear the curse without stopping the thermometer first.
        $actions = $this->stateAs($ctx, $ctx['seeker'])['available_actions'];
        $this->assertContains('stop_thermometer', $actions);
        $this->assertContains('complete_curse', $actions, 'a curse can be cleared mid-thermometer');
    }
}
