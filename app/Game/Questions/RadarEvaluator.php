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
        // The asker's own position is the centre of the radar, so the cut is only as good as
        // their fix. Unknown or too rough to judge → hand it back for a manual answer rather
        // than auto-answering from a point that may be a block away.
        if ($hiderPoint === null || ! $asker->hasReliableFix()) {
            return null; // positions unknown — fall back to a manual answer
        }
        [$hLat, $hLng] = $hiderPoint;

        $radius = (float) ($payload['radius_m'] ?? 0);
        $within = Geo::distanceMeters((float) $asker->last_lat, (float) $asker->last_lng, $hLat, $hLng) <= $radius;

        return ['answer' => $within ? 'yes' : 'no', 'radius_m' => $radius];
    }
}
