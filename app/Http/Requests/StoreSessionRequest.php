<?php

namespace App\Http\Requests;

use App\Enums\GameSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'game_mode' => ['nullable', Rule::in(array_keys(config('game.modes', [])))],
            'city' => ['required', 'string', Rule::exists('cities', 'key')->where('is_active', true)],
            // The play size is tied to the city (admin-set); accepted for back-compat but ignored.
            'game_size' => ['nullable', Rule::enum(GameSize::class)],
            'config' => ['nullable', 'array'],
            'config.transit_modes' => ['sometimes', 'array', 'min:1'],
            'config.transit_modes.*' => ['string', Rule::in(['metro', 'tram', 'rail', 'light_rail', 'bus', 'trolleybus'])],
            'config.hiding_zone_rule' => ['sometimes', Rule::in(['circle', 'nearest'])],
            'config.reveal_seekers_to_hider' => ['sometimes', 'boolean'],
            'config.endgame_questions' => ['sometimes', 'boolean'],
            // The host's curated deck: ids of the cards to keep in play (empty/absent = all).
            'config.deck_cards' => ['sometimes', 'array'],
            'config.deck_cards.*' => ['string'],
            'display_name' => ['nullable', 'string', 'max:50'],
        ];
    }
}
