<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuestAuthRequest extends FormRequest
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
            'display_name' => ['nullable', 'string', 'max:50'],
        ];
    }
}
