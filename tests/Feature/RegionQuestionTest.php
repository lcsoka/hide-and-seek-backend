<?php

namespace Tests\Feature;

use App\Game\Geo\ArrayMapDataSource;
use App\Game\Geo\ArrayRegionSource;
use App\Game\Geo\GeoArea;
use App\Game\Geo\GeoBoundaryHit;
use App\Game\Geo\GeoFeature;
use App\Game\Questions\MatchingEvaluator;
use App\Game\Questions\MeasuringEvaluator;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;
use Tests\TestCase;

/**
 * The region/attribute mechanics that go beyond point-nearest: station name-length (A),
 * administrative-division containment (B) and distance-to-boundary (C). Evaluators are exercised
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

    private function noRegions(): ArrayRegionSource
    {
        return new ArrayRegionSource;
    }

    // A) Station Name's Length ---------------------------------------------------------------------

    public function test_station_name_length_matches_on_length_not_identity(): void
    {
        [$session, $asker] = $this->pair(47.60, 19.20, 47.50, 19.00);
        $q = $this->question(['feature' => 'rail_station', 'match' => 'name_length']);

        // Hider nearest "Keleti" (6), seeker nearest "Nyugati" (7) → different length → no.
        $diff = new MatchingEvaluator(new ArrayMapDataSource([
            new GeoFeature('h', 'rail_station', 47.60, 19.20, 'Keleti'),
            new GeoFeature('s', 'rail_station', 47.50, 19.00, 'Nyugati'),
        ]), $this->noRegions());
        $this->assertSame('no', $diff->evaluate($session, $asker, $q, [])['answer']);

        // Different stations, but both 6 letters → same length → yes.
        $same = new MatchingEvaluator(new ArrayMapDataSource([
            new GeoFeature('h', 'rail_station', 47.60, 19.20, 'Keleti'),
            new GeoFeature('s', 'rail_station', 47.50, 19.00, 'Szeged'),
        ]), $this->noRegions());
        $result = $same->evaluate($session, $asker, $q, []);
        $this->assertSame('yes', $result['answer']);
        $this->assertSame('Szeged', $result['feature_name']);          // seeker's own (to read the length)
        $this->assertSame('Keleti', $result['hider_nearest']['name']); // hider-only
    }

    // B) Administrative-division containment --------------------------------------------------------

    public function test_admin_matching_is_yes_only_in_the_same_area(): void
    {
        // North of 47.55 → "north" area, south → "south" area (id decides same/different).
        $regions = new ArrayRegionSource(areaFn: fn (float $lat, float $lng, int $level): GeoArea => new GeoArea(
            $lat > 47.55 ? 'north' : 'south', $level, $lat > 47.55 ? 'Északi vármegye' : 'Déli vármegye',
        ));
        $matching = new MatchingEvaluator(new ArrayMapDataSource, $regions);
        $q = $this->question(['admin_level' => 6]);

        // Hider north, seeker south → different area → no (+ reveals the seeker's area).
        [$s1, $a1] = $this->pair(47.60, 19.20, 47.50, 19.00);
        $r1 = $matching->evaluate($s1, $a1, $q, []);
        $this->assertSame('no', $r1['answer']);
        $this->assertSame('Déli vármegye', $r1['feature_name']);
        $this->assertSame('Északi vármegye', $r1['hider_nearest']['name']);

        // Both north → same area → yes.
        [$s2, $a2] = $this->pair(47.60, 19.20, 47.58, 19.10);
        $this->assertSame('yes', $matching->evaluate($s2, $a2, $q, [])['answer']);
    }

    // C) Distance to a boundary line ---------------------------------------------------------------

    public function test_border_measuring_compares_distance_to_the_boundary(): void
    {
        // A single border at lat 48.0; distance grows with how far south you are.
        $regions = new ArrayRegionSource(boundaryFn: fn (float $lat, float $lng, int $level): GeoBoundaryHit => new GeoBoundaryHit(
            abs(48.0 - $lat) * 111000.0, 48.0, $lng,
        ));
        $measuring = new MeasuringEvaluator(new ArrayMapDataSource, $regions);
        $q = $this->question(['boundary_level' => 2]);

        // Hider (47.60) is nearer the northern border than the seeker (47.50) → closer.
        [$s1, $a1] = $this->pair(47.60, 19.20, 47.50, 19.00);
        $r1 = $measuring->evaluate($s1, $a1, $q, []);
        $this->assertSame('closer', $r1['answer']);
        $this->assertEqualsWithDelta(48.0, $r1['feature_lat'], 1e-9); // seeker's nearest border point

        // Hider further south than the seeker → further.
        [$s2, $a2] = $this->pair(47.30, 19.20, 47.50, 19.00);
        $this->assertSame('further', $measuring->evaluate($s2, $a2, $q, [])['answer']);
    }

    // Geo helper -----------------------------------------------------------------------------------

    public function test_nearest_on_path_projects_onto_the_segment(): void
    {
        // Horizontal segment at lat 47.5 (lng 19.0→19.1); query point ~1.1 km due north of its middle.
        $near = Geo::nearestOnPath(47.51, 19.05, [[47.5, 19.0], [47.5, 19.1]]);

        $this->assertNotNull($near);
        $this->assertEqualsWithDelta(47.5, $near['lat'], 0.001);
        $this->assertEqualsWithDelta(19.05, $near['lng'], 0.002);
        $this->assertEqualsWithDelta(1113, $near['distance'], 80); // 0.01° lat ≈ 1113 m
        $this->assertNull(Geo::nearestOnPath(47.5, 19.0, []));
    }
}
