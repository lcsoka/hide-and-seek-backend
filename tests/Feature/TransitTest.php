<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Models\Player;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/** Seekers' public-transport board/alight: the journey log + the walk-only thermometer rule. */
class TransitTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([GameEventBroadcast::class]);
    }

    private function state(array $ctx): array
    {
        Sanctum::actingAs($ctx['seeker']);

        return $this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json();
    }

    public function test_board_then_alight_records_a_journey_leg(): void
    {
        $ctx = $this->startSeeking();
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'board_transit'])->assertOk();

        $boarded = $this->state($ctx);
        $this->assertTrue($boarded['transit']['on_transit']);
        $this->assertContains('alight_transit', $boarded['available_actions']);
        $this->assertNotContains('board_transit', $boarded['available_actions']);

        // Travel, then alight — the leg is logged with a distance + duration.
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.5200, 'last_lng' => 19.0700]);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'alight_transit'])->assertOk();

        $after = $this->state($ctx);
        $this->assertFalse($after['transit']['on_transit']);
        $this->assertCount(1, $after['transit']['log']);
        $leg = $after['transit']['log'][0];
        $this->assertSame($ctx['seekerId'], $leg['player_id']);
        $this->assertGreaterThan(0, $leg['distance_m']);
        $this->assertContains('board_transit', $after['available_actions']);
    }

    public function test_thermometer_is_walk_only_blocked_while_on_transit(): void
    {
        $ctx = $this->startSeeking();
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'board_transit'])->assertOk();

        // On transit: the thermometer is neither offered nor allowed.
        $onTransit = $this->state($ctx);
        $this->assertNotContains('start_thermometer', $onTransit['available_actions']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'start_thermometer', 'payload' => ['distance_m' => 800]])
            ->assertStatus(422);
    }

    public function test_cannot_board_during_a_thermometer(): void
    {
        $ctx = $this->startSeeking();
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'start_thermometer', 'payload' => ['distance_m' => 800]])->assertOk();

        $running = $this->state($ctx);
        $this->assertNotContains('board_transit', $running['available_actions']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'board_transit'])->assertStatus(422);
    }

    public function test_transit_state_resets_between_rounds(): void
    {
        $ctx = $this->startSeeking();
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.4979, 'last_lng' => 19.0402]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'board_transit'])->assertOk();
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'alight_transit'])->assertOk();

        // Force a multi-round game and advance the round (endgame → hider surrenders → next round).
        $s = Session::find($ctx['sessionId']);
        $s->update(['config' => array_merge($s->config, ['rounds' => 2])]);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'declare_endgame'])->assertOk();
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'surrender'])->assertOk();
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'advance_round'])->assertOk();

        $this->assertArrayNotHasKey('transit_log', Session::find($ctx['sessionId'])->state_data);
    }
}
