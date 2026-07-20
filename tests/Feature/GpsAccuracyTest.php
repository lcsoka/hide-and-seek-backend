<?php

namespace Tests\Feature;

use App\Game\Questions\RadarEvaluator;
use App\Models\Player;
use App\Models\PlayerPosition;
use App\Models\Question;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeekingScenario;
use Tests\TestCase;

/**
 * A phone that loses satellite lock falls back to a wifi/cell-tower fix that can be hundreds of
 * metres off while still reporting a position with full confidence. Taking those at face value
 * does not merely blur the game, it corrupts it — so anything that decides something checks the
 * reported accuracy first (Player::hasReliableFix, threshold in config game.location).
 */
class GpsAccuracyTest extends TestCase
{
    use RefreshDatabase, SeekingScenario;

    /** Comfortably past the 50 m threshold — a plausible indoor wifi fix. */
    private const POOR = 300.0;

    /** A normal clear-sky phone fix. */
    private const GOOD = 8.0;

    private const ZONE = ['center' => ['lat' => 47.50, 'lng' => 19.04], 'radius_m' => 400, 'rule' => 'circle'];

    private function patchState(string $sessionId, array $patch): void
    {
        $s = Session::find($sessionId);
        $s->update(['state_data' => array_merge($s->state_data, $patch)]);
    }

    private function report(string $sessionId, float $lat, float $lng, ?float $accuracy = null)
    {
        $payload = ['lat' => $lat, 'lng' => $lng];
        if ($accuracy !== null) {
            $payload['accuracy'] = $accuracy;
        }

        return $this->postJson("/api/v1/sessions/{$sessionId}/location", $payload);
    }

    /** @return array<int, string> */
    private function seekerActions(array $ctx): array
    {
        Sanctum::actingAs($ctx['seeker']);

        return $this->getJson("/api/v1/sessions/{$ctx['sessionId']}/state")->json('available_actions') ?? [];
    }

    public function test_reported_accuracy_is_stored_on_the_player_and_the_replay_track(): void
    {
        $ctx = $this->startSeeking();

        Sanctum::actingAs($ctx['seeker']);
        $this->report($ctx['sessionId'], 47.501, 19.041, 12.5)->assertNoContent();

        $this->assertSame(12.5, Player::find($ctx['seekerId'])->last_accuracy_m);
        $this->assertSame(12.5, PlayerPosition::where('player_id', $ctx['seekerId'])->first()->accuracy_m);
    }

    public function test_a_fix_that_reports_no_accuracy_is_still_trusted(): void
    {
        // Clients from before this existed — and the dev harness, which places players by hand —
        // send no accuracy at all. Those readings must keep driving the game as they always did.
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], ['hiding_zone' => self::ZONE]);

        Sanctum::actingAs($ctx['seeker']);
        $this->report($ctx['sessionId'], 47.501, 19.041)->assertNoContent();

        $this->assertNull(Player::find($ctx['seekerId'])->last_accuracy_m);
        $this->assertNotNull(Session::find($ctx['sessionId'])->state_data['endgame_dwell'] ?? null);
    }

    public function test_a_poor_fix_does_not_start_the_endgame_dwell(): void
    {
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], ['hiding_zone' => self::ZONE]);

        Sanctum::actingAs($ctx['seeker']);
        // Dead centre of the hiding zone on paper, but the phone admits it could be 300 m out —
        // the seeker may well be on the far side of the neighbourhood.
        $this->report($ctx['sessionId'], 47.500, 19.040, self::POOR)->assertNoContent();

        $this->assertNull(Session::find($ctx['sessionId'])->state_data['endgame_dwell'] ?? null);
    }

    public function test_a_poor_fix_does_not_cancel_a_dwell_already_running(): void
    {
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], ['hiding_zone' => self::ZONE]);

        Sanctum::actingAs($ctx['seeker']);
        $this->report($ctx['sessionId'], 47.501, 19.041, self::GOOD)->assertNoContent();
        $dwell = Session::find($ctx['sessionId'])->state_data['endgame_dwell'] ?? null;
        $this->assertNotNull($dwell);

        // A single junk reading placing them far away must not reset a seeker who never moved.
        $this->report($ctx['sessionId'], 47.60, 19.30, self::POOR)->assertNoContent();

        $this->assertSame($dwell, Session::find($ctx['sessionId'])->state_data['endgame_dwell'] ?? null);
    }

    public function test_a_poor_fix_does_not_move_the_hiders_committed_spot(): void
    {
        // The committed spot is the ground truth every question is judged against; letting a bad
        // reading drag it across the zone would silently invalidate every later cut.
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], ['hiding_zone' => self::ZONE]);

        Sanctum::actingAs($ctx['host']); // the host is the hider in this scenario
        $this->report($ctx['sessionId'], 47.5010, 19.0410, self::GOOD)->assertNoContent();
        $committed = Session::find($ctx['sessionId'])->state_data['hider_position'];
        $this->assertEqualsWithDelta(47.5010, $committed['lat'], 1e-6);

        // Still inside the zone, so only the accuracy can reject it.
        $this->report($ctx['sessionId'], 47.5025, 19.0425, self::POOR)->assertNoContent();

        $this->assertEqualsWithDelta(47.5010, Session::find($ctx['sessionId'])->state_data['hider_position']['lat'], 1e-6);
    }

    public function test_a_poor_fix_does_not_hand_the_seeker_a_catch(): void
    {
        $ctx = $this->startSeeking();
        $this->patchState($ctx['sessionId'], ['hider_position' => ['lat' => 47.50, 'lng' => 19.04]]);

        Sanctum::actingAs($ctx['seeker']);
        // Standing on top of the hider on paper — but the 75 m catch radius is far smaller than
        // this reading's own error, so the claim must stay out of reach.
        $this->report($ctx['sessionId'], 47.5001, 19.0401, self::POOR)->assertNoContent();
        $this->assertNotContains('claim_found', $this->seekerActions($ctx));

        // The same spot with a clean fix does allow it.
        Sanctum::actingAs($ctx['seeker']);
        $this->report($ctx['sessionId'], 47.5001, 19.0401, self::GOOD)->assertNoContent();
        $this->assertContains('claim_found', $this->seekerActions($ctx));
    }

    public function test_a_poor_asker_fix_falls_back_to_a_manual_answer(): void
    {
        // The asker's position is the centre of a radar cut, so a rough fix would auto-answer
        // from a point that may be a block away. Returning null hands the question back to the
        // hider to answer by hand instead of quietly answering it wrong.
        $session = new Session;
        $session->state_data = ['hider_position' => ['lat' => 47.50, 'lng' => 19.04]];
        $question = new Question(['category' => 'radar']);
        $evaluator = app(RadarEvaluator::class);

        $sloppy = new Player(['last_lat' => 47.50, 'last_lng' => 19.04, 'last_accuracy_m' => self::POOR]);
        $this->assertNull($evaluator->evaluate($session, $sloppy, $question, ['radius_m' => 1000]));

        $clean = new Player(['last_lat' => 47.50, 'last_lng' => 19.04, 'last_accuracy_m' => self::GOOD]);
        $this->assertNotNull($evaluator->evaluate($session, $clean, $question, ['radius_m' => 1000]));
    }
}
