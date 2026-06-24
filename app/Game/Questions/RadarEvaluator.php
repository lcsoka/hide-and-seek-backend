<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Support\Geo;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * "Are you within R of me?" — yes/no by distance from the asking seeker to the
 * hider. Reveals only the boolean (never the actual distance).
 */
class RadarEvaluator implements QuestionEvaluator
{
    public function category(): QuestionCategory
    {
        return QuestionCategory::Radar;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $hider = $session->players()->find($session->state_data['hider_id'] ?? null);

        if ($hider === null
            || $hider->last_lat === null || $hider->last_lng === null
            || $asker->last_lat === null || $asker->last_lng === null) {
            return null; // positions unknown — fall back to a manual answer
        }

        $radius = (float) ($payload['radius_m'] ?? 0);
        $within = Geo::distanceMeters(
            (float) $asker->last_lat, (float) $asker->last_lng,
            (float) $hider->last_lat, (float) $hider->last_lng,
        ) <= $radius;

        return ['answer' => $within ? 'yes' : 'no', 'radius_m' => $radius];
    }
}
