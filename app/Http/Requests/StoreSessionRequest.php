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
            'city' => ['required', Rule::in(array_keys(config('game.cities', [])))],
            'game_size' => ['required', Rule::enum(GameSize::class)],
            'config' => ['nullable', 'array'],
            'config.transit_modes' => ['sometimes', 'array', 'min:1'],
            'config.transit_modes.*' => ['string', Rule::in(['metro', 'tram', 'rail', 'light_rail', 'bus', 'trolleybus'])],
            'display_name' => ['nullable', 'string', 'max:50'],
        ];
    }
}
