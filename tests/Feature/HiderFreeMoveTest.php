<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Game\GameEngine;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/** The hider roams inside their zone during the run; the endgame locks their final spot. */
class HiderFreeMoveTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([GameEventBroadcast::class]);
    }

    private function setZone(string $sessionId): void
    {
        $s = Session::find($sessionId);
        $s->update(['state_data' => array_merge($s->state_data, [
            'hiding_zone' => ['center' => ['lat' => 47.50, 'lng' => 19.04], 'radius_m' => 400, 'rule' => 'circle'],
        ])]);
    }

    private function committedSpot(string $sessionId): ?array
    {
        return Session::find($sessionId)->state_data['hider_position'] ?? null;
    }

    public function test_hider_spot_follows_them_within_the_zone_while_seeking(): void
    {
        $ctx = $this->startSeeking();
        $this->setZone($ctx['sessionId']);

        // The hider walks to a new spot inside the zone → the committed spot moves with them.
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.5015, 'lng' => 19.0385])->assertNoContent();
        $spot = $this->committedSpot($ctx['sessionId']);
        $this->assertEqualsWithDelta(47.5015, $spot['lat'], 1e-6);
        $this->assertEqualsWithDelta(19.0385, $spot['lng'], 1e-6);
    }

    public function test_a_fix_outside_the_zone_does_not_move_the_spot(): void
    {
        $ctx = $this->startSeeking();
        $this->setZone($ctx['sessionId']);

        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.5015, 'lng' => 19.0385])->assertNoContent();
        // A fix well outside the 400m zone is ignored — the spot stays at the last in-zone point.
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.70, 'lng' => 19.40])->assertNoContent();
        $spot = $this->committedSpot($ctx['sessionId']);
        $this->assertEqualsWithDelta(47.5015, $spot['lat'], 1e-6);
    }

    public function test_the_endgame_locks_the_spot(): void
    {
        $ctx = $this->startSeeking();
        $this->setZone($ctx['sessionId']);

        // Roam to a spot, then a seeker triggers the endgame.
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.5015, 'lng' => 19.0385])->assertNoContent();
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'declare_endgame'])->assertOk();
        $this->assertSame('endgame', Session::find($ctx['sessionId'])->state);

        $locked = $this->committedSpot($ctx['sessionId']);

        // Further hider movement inside the zone no longer moves the (now locked) spot.
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.4995, 'lng' => 19.0420])->assertNoContent();
        $this->assertEqualsWithDelta($locked['lat'], $this->committedSpot($ctx['sessionId'])['lat'], 1e-9);

        // The hider is reported as locked in their own state view.
        $this->assertTrue($this->getJson("/api/sessions/{$ctx['sessionId']}/state")->json('hider_locked'));
    }
}
