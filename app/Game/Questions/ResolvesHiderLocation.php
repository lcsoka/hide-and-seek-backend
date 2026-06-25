<?php

namespace App\Game\Questions;

use App\Models\Session;

/**
 * The hider's committed hiding point. Snapshotted at confirm_hidden so every question
 * is answered against the SAME fixed spot — otherwise the hider's live GPS drifting
 * within their zone would make earlier answers contradict the deduction (and let them
 * appear to "move out of" the deduced area). Falls back to live GPS pre-snapshot.
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
