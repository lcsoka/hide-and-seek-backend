<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * "After you travel, are you hotter or colder?" — deferred: the ask captures the
 * seeker's start position; resolution compares the seeker's *current* distance to
 * the hider against that start distance.
 */
class ThermometerEvaluator implements DeferredQuestionEvaluator
{
    public function category(): QuestionCategory
    {
        return QuestionCategory::Thermometer;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $startLat = $payload['start_lat'] ?? null;
        $startLng = $payload['start_lng'] ?? null;
        $hider = $session->players()->find($session->state_data['hider_id'] ?? null);

        if ($startLat === null || $startLng === null || $hider === null
            || $hider->last_lat === null || $hider->last_lng === null
            || $asker->last_lat === null || $asker->last_lng === null) {
            return null;
        }

        $startDistance = Geo::distanceMeters((float) $startLat, (float) $startLng, (float) $hider->last_lat, (float) $hider->last_lng);
        $nowDistance = Geo::distanceMeters((float) $asker->last_lat, (float) $asker->last_lng, (float) $hider->last_lat, (float) $hider->last_lng);

        return ['answer' => $nowDistance < $startDistance ? 'hotter' : 'colder'];
    }
}
