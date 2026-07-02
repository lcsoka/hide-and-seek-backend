<?php

namespace App\Models;

use App\Enums\QuestionCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class Question extends Model
{
    use HasTranslations, HasUuids;

    /** Translatable (per-locale) attributes — stored as JSON by spatie. */
    public array $translatable = ['title', 'prompt'];

    protected $fillable = [
        'key',
        'category',
        'title',
        'prompt',
        'reward_draw',
        'reward_keep',
        'answer_time_s',
        'parameters',
        'is_custom',
        'is_active',
        'sort',
        'user_id',
    ];

    /** The author of a custom question (null = official content). */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected $casts = [
        'category' => QuestionCategory::class,
        'parameters' => 'array',
        'reward_draw' => 'integer',
        'reward_keep' => 'integer',
        'answer_time_s' => 'integer',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];
}
