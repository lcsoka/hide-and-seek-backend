<?php

namespace App\Http\Requests;

use App\Enums\FeedbackType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint — anyone may submit a suggestion or bug report.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(FeedbackType::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'session_id' => ['nullable', 'uuid', 'exists:game_sessions,id'],
            'player_id' => ['nullable', 'uuid', 'exists:players,id'],
            'contact' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', 'array'],
        ];
    }
}
