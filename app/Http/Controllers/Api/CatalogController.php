<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\City;
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

    /** Playable cities for the new-game wizard: cover image, the size tied to the city, and the
     *  transit modes that actually exist there. */
    public function cities()
    {
        return City::query()->where('is_active', true)->orderBy('sort')->get()->map(fn (City $c) => [
            'key' => $c->key,
            'name' => $c->name,
            'lat' => $c->lat,
            'lng' => $c->lng,
            'image' => $c->imageUrl(),
            'size' => $c->default_size->value,
            'modes' => $c->available_modes,
        ]);
    }

    public function curses()
    {
        return Card::query()->where('type', 'curse')->where('is_active', true)->orderBy('sort')->get()->map(fn (Card $c) => [
            'id' => $c->id,
            'key' => $c->key,
            'name' => $c->name,
            'cost' => $c->cost,
            'description' => $c->description,
            'effect' => $c->effect,
        ]);
    }

    /**
     * The full hider deck the host can curate in the new-game wizard: every official card plus the
     * host's OWN custom curses, so they can toggle any of them in or out of the game. Auth-scoped.
     */
    public function deck(\Illuminate\Http\Request $request)
    {
        $userId = $request->user()?->id;

        return Card::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId))
            ->orderBy('type')->orderBy('sort')->get()->map(fn (Card $c) => [
                'id' => $c->id,
                'key' => $c->key,
                'type' => $c->type,
                'name' => $c->name,
                'cost' => $c->cost,
                'description' => $c->description,
                'is_custom' => (bool) $c->is_custom,
            ]);
    }
}
