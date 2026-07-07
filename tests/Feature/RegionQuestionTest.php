<?php

namespace Tests\Feature;

use App\Game\Geo\ArrayMapDataSource;
use App\Game\Geo\GeoFeature;
use App\Game\Questions\MatchingEvaluator;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;
use Tests\TestCase;

/**
 * The region/attribute matching mechanics that go beyond point-nearest: station name-length (A),
 * administrative-division containment (B), and distance-to-boundary (C). Evaluators are exercised
 * directly with in-memory data sources — no session, DB, or network.
 */
class RegionQuestionTest extends TestCase
{
    private function question(array $parameters): Question
    {
        $q = new Question;
        $q->parameters = $parameters;

        return $q;
    }

    /** @return array{0: Session, 1: Player} an unsaved session (hider point) + asking seeker */
    private function pair(float $hLat, float $hLng, float $sLat, float $sLng): array
    {
        return [
            new Session(['state_data' => ['hider_position' => ['lat' => $hLat, 'lng' => $hLng]]]),
            new Player(['last_lat' => $sLat, 'last_lng' => $sLng]),
        ];
    }

    public function test_station_name_length_matches_on_length_not_identity(): void
    {
        [$session, $asker] = $this->pair(47.60, 19.20, 47.50, 19.00);
        $q = $this->question(['feature' => 'rail_station', 'match' => 'name_length']);

        // Hider nearest "Keleti" (6), seeker nearest "Nyugati" (7) → different length → no.
        $diff = new MatchingEvaluator(new ArrayMapDataSource([
            new GeoFeature('h', 'rail_station', 47.60, 19.20, 'Keleti'),
            new GeoFeature('s', 'rail_station', 47.50, 19.00, 'Nyugati'),
        ]));
        $this->assertSame('no', $diff->evaluate($session, $asker, $q, [])['answer']);

        // Different stations, but both 6 letters → same length → yes.
        $same = new MatchingEvaluator(new ArrayMapDataSource([
            new GeoFeature('h', 'rail_station', 47.60, 19.20, 'Keleti'),
            new GeoFeature('s', 'rail_station', 47.50, 19.00, 'Szeged'),
        ]));
        $result = $same->evaluate($session, $asker, $q, []);
        $this->assertSame('yes', $result['answer']);
        // Reveals the seeker's own nearest (so they can read the length) + the hider's (hider-only).
        $this->assertSame('Szeged', $result['feature_name']);
        $this->assertSame('Keleti', $result['hider_nearest']['name']);
    }
}
