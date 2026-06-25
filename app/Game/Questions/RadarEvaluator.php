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
    use ResolvesHiderLocation;

    public function category(): QuestionCategory
    {
        return QuestionCategory::Radar;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $hiderPoint = $this->hiderPoint($session);
        if ($hiderPoint === null || $asker->last_lat === null || $asker->last_lng === null) {
            return null; // positions unknown — fall back to a manual answer
        }
        [$hLat, $hLng] = $hiderPoint;

        $radius = (float) ($payload['radius_m'] ?? 0);
        $within = Geo::distanceMeters((float) $asker->last_lat, (float) $asker->last_lng, $hLat, $hLng) <= $radius;

        return ['answer' => $within ? 'yes' : 'no', 'radius_m' => $radius];
    }
}
