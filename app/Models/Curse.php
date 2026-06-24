<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Curse extends Model
{
    use HasTranslations, HasUuids;

    /** Translatable (per-locale) attributes — stored as JSON by spatie. */
    public array $translatable = ['name', 'cost', 'description'];

    protected $fillable = [
        'key',
        'name',
        'cost',
        'description',
        'parameters',
        'is_custom',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'parameters' => 'array',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];
}
