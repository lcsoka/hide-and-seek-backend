<?php

namespace App\Models;

use App\Enums\QuestionCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasUuids;

    protected $fillable = [
        'category',
        'title',
        'prompt',
        'reward_draw',
        'reward_keep',
        'parameters',
        'is_custom',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'category' => QuestionCategory::class,
        'parameters' => 'array',
        'reward_draw' => 'integer',
        'reward_keep' => 'integer',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];
}
