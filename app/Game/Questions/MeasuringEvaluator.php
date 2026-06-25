<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Geo\MapDataSource;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * "Is the hider closer to MY nearest {feature} than I am?" — the reference is the
 * feature nearest the asking seeker; both players' distances are measured to THAT
 * same feature.
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

        // The reference is the feature closest to the SEEKER; compare both to it.
        $reference = $this->map->nearest($feature, (float) $asker->last_lat, (float) $asker->last_lng);
        if ($reference === null) {
            return null;
        }

        $askerDistance = Geo::distanceMeters((float) $asker->last_lat, (float) $asker->last_lng, $reference->lat, $reference->lng);
        $hiderDistance = Geo::distanceMeters((float) $hider->last_lat, (float) $hider->last_lng, $reference->lat, $reference->lng);

        return [
            'answer' => $hiderDistance <= $askerDistance ? 'closer' : 'further',
            'feature_name' => $reference->name,
            'feature_lat' => $reference->lat,
            'feature_lng' => $reference->lng,
        ];
    }
}
