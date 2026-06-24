<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Geo\MapDataSource;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * "Compared to me, are you closer to or further from the nearest {feature}?" —
 * compares each player's distance to their own nearest feature of the type.
 */
class MeasuringEvaluator implements QuestionEvaluator
{
    public function __construct(private readonly MapDataSource $map) {}

    public function category(): QuestionCategory
    {
        return QuestionCategory::Measuring;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $feature = $payload['feature'] ?? ($question->parameters['feature'] ?? null);
        $hider = $session->players()->find($session->state_data['hider_id'] ?? null);

        if (! is_string($feature) || $hider === null
            || $hider->last_lat === null || $hider->last_lng === null
            || $asker->last_lat === null || $asker->last_lng === null) {
            return null;
        }

        $hiderNearest = $this->map->nearest($feature, (float) $hider->last_lat, (float) $hider->last_lng);
        $askerNearest = $this->map->nearest($feature, (float) $asker->last_lat, (float) $asker->last_lng);

        if ($hiderNearest === null || $askerNearest === null) {
            return null;
        }

        $hiderDistance = Geo::distanceMeters((float) $hider->last_lat, (float) $hider->last_lng, $hiderNearest->lat, $hiderNearest->lng);
        $askerDistance = Geo::distanceMeters((float) $asker->last_lat, (float) $asker->last_lng, $askerNearest->lat, $askerNearest->lng);

        return ['answer' => $hiderDistance <= $askerDistance ? 'closer' : 'further'];
    }
}
