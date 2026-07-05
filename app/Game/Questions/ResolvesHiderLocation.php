<?php

namespace App\Game\Questions;

use App\Models\Session;

/**
 * The hider's committed hiding point. During seeking it tracks the hider's live position within
 * their zone (frozen while a question is pending, so each answer is consistent with the ask), and
 * the endgame locks it. Questions and the catch are both judged against this point. Falls back to
 * live GPS before any spot is recorded.
 */
trait ResolvesHiderLocation
{
    /** @return array{0: float, 1: float}|null [lat, lng] */
    protected function hiderPoint(Session $session): ?array
    {
        $pos = $session->state_data['hider_position'] ?? null;
        if (isset($pos['lat'], $pos['lng'])) {
            return [(float) $pos['lat'], (float) $pos['lng']];
        }

        $hider = $session->players()->find($session->state_data['hider_id'] ?? null);
        if ($hider !== null && $hider->last_lat !== null && $hider->last_lng !== null) {
            return [(float) $hider->last_lat, (float) $hider->last_lng];
        }

        return null;
    }
}
