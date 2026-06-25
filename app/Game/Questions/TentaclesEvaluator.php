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

        if (! is_string($feature) || $radius <= 0 || $hiderPoint === null
            || $asker->last_lat === null || $asker->last_lng === null) {
            return null;
        }

        $inRange = Geo::distanceMeters((float) $asker->last_lat, (float) $asker->last_lng, $hiderPoint[0], $hiderPoint[1]) <= $radius;

        if (! $inRange) {
            return ['answer' => 'out_of_range'];
        }

        $nearest = $this->map->nearest($feature, $hiderPoint[0], $hiderPoint[1]);
        if ($nearest === null) {
            return null; // in range but no map data — manual fallback
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
