<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Geo\MapDataSource;
use App\Game\Geo\RegionSource;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * "Is your nearest {feature} the same as mine?" — compares the hider's nearest
 * feature against the SEEKER's reference place. The seeker confirms/selects their
 * actual closest place in the app (`ref_lat`/`ref_lng`); if they didn't, we fall back
 * to the OSM-computed nearest.
 */
class MatchingEvaluator implements QuestionEvaluator
{
    use ResolvesHiderLocation;

    /** A hider feature within this distance of the seeker's place counts as "the same place". */
    private const SAME_PLACE_M = 150.0;

    public function __construct(
        private readonly MapDataSource $map,
        private readonly RegionSource $regions,
    ) {}

    public function category(): QuestionCategory
    {
        return QuestionCategory::Matching;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $feature = $payload['feature'] ?? ($question->parameters['feature'] ?? null);
        $hiderPoint = $this->hiderPoint($session);

        if ($hiderPoint === null) {
            return null;
        }

        // "Same administrative division?" — do the hider and seeker fall in the same area at this level?
        if (isset($question->parameters['admin_level'])) {
            return $this->evaluateAdmin($asker, (int) $question->parameters['admin_level'], $hiderPoint);
        }

        if (! is_string($feature)) {
            return null;
        }

        $hiderNearest = $this->map->nearest($feature, $hiderPoint[0], $hiderPoint[1]);
        if ($hiderNearest === null) {
            return null; // no map data — fall back to a manual answer
        }

        // The hider's own nearest feature — shown ONLY to the hider (so they can answer
        // "same?" knowingly). Stripped before the answer reaches the seeker-visible history.
        $hiderNearestInfo = ['name' => $hiderNearest->name, 'lat' => $hiderNearest->lat, 'lng' => $hiderNearest->lng];

        // "Station Name's Length": compare the NAME length of each side's nearest feature, not identity.
        if (($question->parameters['match'] ?? null) === 'name_length') {
            if (! $asker->hasReliableFix()) {
                return null;
            }
            $askerNearest = $this->map->nearest($feature, (float) $asker->last_lat, (float) $asker->last_lng);
            if ($askerNearest === null) {
                return null;
            }
            $same = mb_strlen((string) $hiderNearest->name) === mb_strlen((string) $askerNearest->name);

            return [
                'answer' => $same ? 'yes' : 'no',
                'feature_name' => $askerNearest->name,
                'feature_lat' => $askerNearest->lat,
                'feature_lng' => $askerNearest->lng,
                'hider_nearest' => $hiderNearestInfo,
            ];
        }

        // The seeker's confirmed reference place, if they picked one.
        $refLat = $payload['ref_lat'] ?? null;
        $refLng = $payload['ref_lng'] ?? null;
        if ($refLat !== null && $refLng !== null) {
            $same = Geo::distanceMeters($hiderNearest->lat, $hiderNearest->lng, (float) $refLat, (float) $refLng) <= self::SAME_PLACE_M;

            return [
                'answer' => $same ? 'yes' : 'no',
                'feature_name' => $payload['ref_name'] ?? null,
                'feature_lat' => (float) $refLat,
                'feature_lng' => (float) $refLng,
                'hider_nearest' => $hiderNearestInfo,
            ];
        }

        // Fallback: compute the seeker's nearest from their own position.
        if (! $asker->hasReliableFix()) {
            return null;
        }
        $askerNearest = $this->map->nearest($feature, (float) $asker->last_lat, (float) $asker->last_lng);
        if ($askerNearest === null) {
            return null;
        }

        // Reveal the SEEKER's nearest feature (the place compared) — never the hider's.
        return [
            'answer' => $hiderNearest->id === $askerNearest->id ? 'yes' : 'no',
            'feature_name' => $askerNearest->name,
            'feature_lat' => $askerNearest->lat,
            'feature_lng' => $askerNearest->lng,
            'hider_nearest' => $hiderNearestInfo,
        ];
    }

    /**
     * "Are you in the same {megye/járás/település/kerület} as me?" — compare the administrative
     * area (of the given admin_level) that contains each side; the seeker's area name is revealed,
     * the hider's is hider-only. The client draws the area polygon from ask.admin_level + the answer.
     *
     * @param  array{0: float, 1: float}  $hiderPoint
     * @return array<string, mixed>|null
     */
    private function evaluateAdmin(Player $asker, int $adminLevel, array $hiderPoint): ?array
    {
        if (! $asker->hasReliableFix()) {
            return null;
        }
        $hiderArea = $this->regions->areaContaining($hiderPoint[0], $hiderPoint[1], $adminLevel);
        $askerArea = $this->regions->areaContaining((float) $asker->last_lat, (float) $asker->last_lng, $adminLevel);
        if ($hiderArea === null || $askerArea === null) {
            return null; // no coverage — fall back to a manual answer
        }

        return [
            'answer' => $hiderArea->id === $askerArea->id ? 'yes' : 'no',
            'feature_name' => $askerArea->name,     // the seeker's own area (the one revealed)
            'admin_level' => $adminLevel,
            'hider_nearest' => ['name' => $hiderArea->name, 'lat' => null, 'lng' => null],
        ];
    }
}
