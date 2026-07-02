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
 * same feature. The seeker confirms/previews their reference place in the app
 * (`ref_lat`/`ref_lng`); if present we use it (and skip Overpass), else we compute
 * the seeker's nearest from OSM.
 */
class MeasuringEvaluator implements QuestionEvaluator
{
    use ResolvesHiderLocation;

    public function __construct(private readonly MapDataSource $map) {}

    public function category(): QuestionCategory
    {
        return QuestionCategory::Measuring;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $feature = $payload['feature'] ?? ($question->parameters['feature'] ?? null);
        $hiderPoint = $this->hiderPoint($session);

        if (! is_string($feature) || $hiderPoint === null
            || $asker->last_lat === null || $asker->last_lng === null) {
            return null;
        }

        // Prefer the seeker's confirmed reference place (what they saw on the map when asking),
        // which keeps the answer consistent with the preview and avoids an Overpass call.
        if (isset($payload['ref_lat'], $payload['ref_lng'])) {
            $refLat = (float) $payload['ref_lat'];
            $refLng = (float) $payload['ref_lng'];
            $refName = $payload['ref_name'] ?? null;
        } else {
            $reference = $this->map->nearest($feature, (float) $asker->last_lat, (float) $asker->last_lng);
            if ($reference === null) {
                return null;
            }
            $refLat = $reference->lat;
            $refLng = $reference->lng;
            $refName = $reference->name;
        }

        $askerDistance = Geo::distanceMeters((float) $asker->last_lat, (float) $asker->last_lng, $refLat, $refLng);
        $hiderDistance = Geo::distanceMeters($hiderPoint[0], $hiderPoint[1], $refLat, $refLng);

        return [
            'answer' => $hiderDistance <= $askerDistance ? 'closer' : 'further',
            'feature_name' => $refName,
            'feature_lat' => $refLat,
            'feature_lng' => $refLng,
        ];
    }
}
