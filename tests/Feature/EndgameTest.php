<?php

namespace Tests\Feature;

use App\Events\GameEventBroadcast;
use App\Game\GameEngine;
use App\Models\Player;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/** Endgame: guess scoring, the zone-dwell auto-trigger, and the 'move' relocate flow. */
class EndgameTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([GameEventBroadcast::class]);
    }

    private function patchState(string $sessionId, array $patch): void
    {
        $s = Session::find($sessionId);
        $s->update(['state_data' => array_merge($s->state_data, $patch)]);
    }

    public function test_the_catch_is_judged_against_the_committed_spot_not_live_drift(): void
    {
        $ctx = $this->startSeeking();
        // Committed spot A; the hider's live GPS has since drifted far to B.
        $this->patchState($ctx['sessionId'], ['hider_position' => ['lat' => 47.50, 'lng' => 19.04]]);
        Player::whereKey($ctx['hiderId'])->update(['last_lat' => 47.80, 'last_lng' => 19.60]);
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'declare_endgame'])->assertOk();

        // Standing on the live drift B, the seeker is NOT close enough to catch.
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.80, 'last_lng' => 19.60]);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'confirm_found'])->assertStatus(422);

        // Standing on the committed spot A, they can catch.
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.50, 'last_lng' => 19.04]);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'confirm_found'])->assertOk();
        $session = Session::find($ctx['sessionId']);
        $this->assertSame('round_end', $session->state);
        $this->assertSame($ctx['seekerId'], $session->state_data['last_round']['found_by']);
    }

    public function test_round_end_exposes_the_reveal_and_standings(): void
    {
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], [
            'hider_position' => ['lat' => 47.50, 'lng' => 19.04],
            'hiding_started_at' => now()->subSeconds(120)->timestamp,
        ]);
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.50, 'last_lng' => 19.04]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'declare_endgame'])->assertOk();
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'confirm_found'])->assertOk();

        $state = $this->getJson("/api/sessions/{$ctx['sessionId']}/state");
        $state->assertJsonPath('state', 'round_end');
        $state->assertJsonPath('last_round.found_by', $ctx['seekerId']);
        $state->assertJsonPath('last_round.hider_id', $ctx['hiderId']);
        $this->assertEqualsWithDelta(47.50, $state->json('last_round.hider_position.lat'), 0.001);
        $this->assertGreaterThanOrEqual(120, $state->json('last_round.seconds'));
        // The hider banked the survival time, so they lead the standings.
        $state->assertJsonPath('standings.0.player_id', $ctx['hiderId']);
    }

    public function test_kept_time_bonus_cards_add_to_the_hider_score(): void
    {
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], [
            'hider_position' => ['lat' => 47.50, 'lng' => 19.04],
            'hiding_started_at' => now()->subSeconds(120)->timestamp,
            'hand' => [['uid' => 'tb', 'type' => 'time_bonus', 'minutes' => 10]], // +600s
        ]);
        Player::whereKey($ctx['seekerId'])->update(['last_lat' => 47.50, 'last_lng' => 19.04]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'declare_endgame'])->assertOk();
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'confirm_found'])->assertOk();

        $state = $this->getJson("/api/sessions/{$ctx['sessionId']}/state");
        // 120s survived + 600s banked bonus.
        $state->assertJsonPath('standings.0.player_id', $ctx['hiderId']);
        $this->assertGreaterThanOrEqual(720, $state->json('standings.0.total_hiding_time_s'));
        $state->assertJsonPath('last_round.time_bonus_s', 600);
    }

    public function test_endgame_auto_starts_after_a_seeker_dwells_in_the_zone(): void
    {
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], ['hiding_zone' => ['center' => ['lat' => 47.50, 'lng' => 19.04], 'radius_m' => 400, 'rule' => 'circle']]);

        // The seeker reports a position inside the zone → the dwell clock + timer start.
        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.501, 'lng' => 19.041])->assertNoContent();
        $session = Session::find($ctx['sessionId']);
        $this->assertNotNull($session->state_data['endgame_dwell'] ?? null);

        // The dwell timer fires while they are still inside → the endgame begins.
        app(GameEngine::class)->fireTimer($session, 'endgame_dwell', $session->state_data['endgame_dwell']);
        $this->assertSame('endgame', Session::find($ctx['sessionId'])->state);
    }

    public function test_passing_through_the_zone_does_not_trigger_the_endgame(): void
    {
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], ['hiding_zone' => ['center' => ['lat' => 47.50, 'lng' => 19.04], 'radius_m' => 400, 'rule' => 'circle']]);

        Sanctum::actingAs($ctx['seeker']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.501, 'lng' => 19.041])->assertNoContent();
        $guard = Session::find($ctx['sessionId'])->state_data['endgame_dwell'];

        // Leaving before the dwell elapses cancels it.
        $this->postJson("/api/sessions/{$ctx['sessionId']}/location", ['lat' => 47.60, 'lng' => 19.30])->assertNoContent();
        $this->assertNull(Session::find($ctx['sessionId'])->state_data['endgame_dwell'] ?? null);

        // The now-stale timer fires → no transition.
        app(GameEngine::class)->fireTimer(Session::find($ctx['sessionId']), 'endgame_dwell', $guard);
        $this->assertSame('seeking', Session::find($ctx['sessionId'])->state);
    }

    public function test_move_powerup_lets_the_hider_recommit_a_new_spot(): void
    {
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], ['hider_position' => ['lat' => 47.50, 'lng' => 19.04]]);
        $this->giveHiderCard($ctx['sessionId'], ['uid' => 'mv1', 'type' => 'powerup', 'power' => 'move']);

        // Playing 'move' drops the committed spot and opens a re-confirm.
        Sanctum::actingAs($ctx['host']);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'play_powerup', 'payload' => ['card_uid' => 'mv1']])->assertOk();
        $session = Session::find($ctx['sessionId']);
        $this->assertTrue($session->state_data['relocating']);
        $this->assertArrayNotHasKey('hider_position', $session->state_data);

        // The hider moves to B and re-confirms → new snapshot, still seeking.
        Player::whereKey($ctx['hiderId'])->update(['last_lat' => 47.70, 'last_lng' => 19.40]);
        $this->postJson("/api/sessions/{$ctx['sessionId']}/actions", ['type' => 'confirm_hidden'])->assertOk();
        $session = Session::find($ctx['sessionId']);
        $this->assertSame('seeking', $session->state);
        $this->assertFalse($session->state_data['relocating'] ?? false);
        $this->assertEqualsWithDelta(47.70, $session->state_data['hider_position']['lat'], 0.001);
    }
}
