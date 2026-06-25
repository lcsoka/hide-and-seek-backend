<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Geo\MapDataSource;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * "Is your nearest {feature} the same as mine?" — compares the hider's and the
 * asking seeker's nearest feature of the requested type.
 */
class MatchingEvaluator implements QuestionEvaluator
{
    public function __construct(private readonly MapDataSource $map) {}

    public function category(): QuestionCategory
    {
        return QuestionCategory::Matching;
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
            return null; // no map data — fall back to a manual answer
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
