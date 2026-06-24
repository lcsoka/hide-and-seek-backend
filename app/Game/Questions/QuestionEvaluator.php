<?php

namespace App\Game\Questions;

use App\Enums\QuestionCategory;
use App\Models\Player;
use App\Models\Question;
use App\Models\Session;

/**
 * Computes the authoritative answer to an asked question from real positions.
 * Pure-geometry categories (radar) need no map data; matching/measuring/tentacles
 * will need PostGIS + an OSM extract.
 */
interface QuestionEvaluator
{
    public function category(): QuestionCategory;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null The answer payload, or null if it can't be
     *                                   auto-evaluated yet (e.g. a position is unknown).
     */
    public function evaluate(Session $session, Player $asker, Question $question, array $payload): ?array;
}
