<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * "Between where I started and where I stopped, am I hotter or colder?" — the seeker
 * starts the thermometer (captures the start), travels, then stops (captures the end).
 * Compares the end's distance to the hider against the start's.
 */
class ThermometerEvaluator implements QuestionEvaluator
{
    public function category(): QuestionCategory
    {
        return QuestionCategory::Thermometer;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $startLat = $payload['start_lat'] ?? null;
        $startLng = $payload['start_lng'] ?? null;
        $endLat = $payload['end_lat'] ?? null;
        $endLng = $payload['end_lng'] ?? null;
        $hider = $session->players()->find($session->state_data['hider_id'] ?? null);

        if ($startLat === null || $startLng === null || $endLat === null || $endLng === null
            || $hider === null || $hider->last_lat === null || $hider->last_lng === null) {
            return null;
        }

        $startDistance = Geo::distanceMeters((float) $startLat, (float) $startLng, (float) $hider->last_lat, (float) $hider->last_lng);
        $endDistance = Geo::distanceMeters((float) $endLat, (float) $endLng, (float) $hider->last_lat, (float) $hider->last_lng);

        return ['answer' => $endDistance < $startDistance ? 'hotter' : 'colder'];
    }
}
