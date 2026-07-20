<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Geo\MapDataSource;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * "Of all {feature} within R of a seeker, which is your nearest?" — if the hider
 * is within R of the asking seeker they reveal their nearest such feature; otherwise
 * they're out of range.
 */
class TentaclesEvaluator implements QuestionEvaluator
{
    use ResolvesHiderLocation;

    public function __construct(private readonly MapDataSource $map) {}

    public function category(): QuestionCategory
    {
        return QuestionCategory::Tentacles;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $feature = $payload['feature'] ?? ($question->parameters['feature'] ?? null);
        $radius = (float) ($payload['radius_m'] ?? ($question->parameters['radius_m'] ?? 0));
        $hiderPoint = $this->hiderPoint($session);

        // The tentacle radius is drawn around the asker, so a rough fix would sweep the wrong
        // set of candidate places.
        if (! is_string($feature) || $radius <= 0 || $hiderPoint === null
            || ! $asker->hasReliableFix()) {
            return null;
        }

        // The query origin is the SEEKER. If the hider isn't within the radius of the
        // seeker it's an automatic miss — no matter how close they are to some other
        // instance of the feature elsewhere.
        $inRange = Geo::distanceMeters((float) $asker->last_lat, (float) $asker->last_lng, $hiderPoint[0], $hiderPoint[1]) <= $radius;

        if (! $inRange) {
            return ['answer' => 'out_of_range'];
        }

        // Candidates are the features within the radius of the SEEKER — exactly the set the
        // seeker's tentacle map is drawn from. The hider reveals which of THOSE they are
        // nearest to, so the revealed place always lands inside the drawn region (a global
        // nearest could fall outside the circle and have no tentacle to match).
        $candidates = $this->map->within($feature, (float) $asker->last_lat, (float) $asker->last_lng, $radius);
        $nearest = null;
        $nearestDistance = INF;
        foreach ($candidates as $candidate) {
            $distance = Geo::distanceMeters($hiderPoint[0], $hiderPoint[1], $candidate->lat, $candidate->lng);
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $candidate;
            }
        }
        if ($nearest === null) {
            return null; // in range but no candidates in the radius (or no map data) — manual fallback
        }

        return [
            'answer' => 'in_range',
            'feature_id' => $nearest->id,
            'feature_name' => $nearest->name,
            'feature_lat' => $nearest->lat,
            'feature_lng' => $nearest->lng,
        ];
    }
}
