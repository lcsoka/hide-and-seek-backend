<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Curse;
use App\Models\Question;

/**
 * Read-only reference data for the clients: the question and curse catalogues
 * (active entries, current-locale strings).
 */
class CatalogController extends Controller
{
    public function questions()
    {
        return Question::query()->where('is_active', true)->orderBy('sort')->get()->map(fn (Question $q) => [
            'id' => $q->id,
            'key' => $q->key,
            'category' => $q->category->value,
            'title' => $q->title,
            'prompt' => $q->prompt,
            'parameters' => $q->parameters,
            'reward_draw' => $q->reward_draw,
            'reward_keep' => $q->reward_keep,
        ]);
    }

    public function curses()
    {
        return Curse::query()->where('is_active', true)->orderBy('sort')->get()->map(fn (Curse $c) => [
            'id' => $c->id,
            'key' => $c->key,
            'name' => $c->name,
            'cost' => $c->cost,
            'description' => $c->description,
            'parameters' => $c->parameters,
        ]);
    }
}
