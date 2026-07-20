<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Game\Geo\MapDataSource;
use App\Game\Geo\RegionSource;
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

    public function __construct(
        private readonly MapDataSource $map,
        private readonly RegionSource $regions,
    ) {}

    public function category(): QuestionCategory
    {
        return QuestionCategory::Measuring;
    }

    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array
    {
        $feature = $payload['feature'] ?? ($question->parameters['feature'] ?? null);
        $hiderPoint = $this->hiderPoint($session);

        // Measured against the asker's position — a rough fix would compare the wrong distances.
        if ($hiderPoint === null || ! $asker->hasReliableFix()) {
            return null;
        }

        // "Are you closer to the {international/megye/járás} border than me?" — compare each side's
        // distance to the nearest administrative boundary line of the given level.
        if (isset($question->parameters['boundary_level'])) {
            return $this->evaluateBorder($asker, (int) $question->parameters['boundary_level'], $hiderPoint);
        }

        if (! is_string($feature)) {
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

    /**
     * "Are you closer to the {international/county/district} border than me?" — compare each side's
     * distance to the nearest boundary line of the given admin_level. The seeker's nearest border
     * point is revealed as the reference (a pin + line, like the feature case).
     *
     * @param  array{0: float, 1: float}  $hiderPoint
     * @return array<string, mixed>|null
     */
    private function evaluateBorder(Player $asker, int $boundaryLevel, array $hiderPoint): ?array
    {
        $hiderHit = $this->regions->nearestBoundary($hiderPoint[0], $hiderPoint[1], $boundaryLevel);
        $askerHit = $this->regions->nearestBoundary((float) $asker->last_lat, (float) $asker->last_lng, $boundaryLevel);
        if ($hiderHit === null || $askerHit === null) {
            return null; // no coverage — fall back to a manual answer
        }

        return [
            'answer' => $hiderHit->distanceM <= $askerHit->distanceM ? 'closer' : 'further',
            'feature_name' => null,
            'feature_lat' => $askerHit->lat,   // the seeker's nearest border point (the reference)
            'feature_lng' => $askerHit->lng,
            // The hider's OWN nearest border point, so their map can show what's closest to them
            // (hider-only, like the matching hider_nearest — never sent to the seekers).
            'hider_nearest' => ['name' => null, 'lat' => $hiderHit->lat, 'lng' => $hiderHit->lng],
            'boundary_level' => $boundaryLevel,
        ];
    }
}
