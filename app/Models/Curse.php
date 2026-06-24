<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Curse extends Model
{
    use HasUuids;

    protected $fillable = [
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
