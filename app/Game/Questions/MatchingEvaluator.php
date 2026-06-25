<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Geo\MapDataSource;
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

    public function __construct(private readonly MapDataSource $map) {}

    public function category(): QuestionCategory
    {
        return QuestionCategory::Matching;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $feature = $payload['feature'] ?? ($question->parameters['feature'] ?? null);
        $hiderPoint = $this->hiderPoint($session);

        if (! is_string($feature) || $hiderPoint === null) {
            return null;
        }

        $hiderNearest = $this->map->nearest($feature, $hiderPoint[0], $hiderPoint[1]);
        if ($hiderNearest === null) {
            return null; // no map data — fall back to a manual answer
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
            ];
        }

        // Fallback: compute the seeker's nearest from their own position.
        if ($asker->last_lat === null || $asker->last_lng === null) {
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
        ];
    }
}
