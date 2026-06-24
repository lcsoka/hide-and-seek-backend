<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id',
        'user_id',
        'display_name',
        'team_id',
        'role',
        'is_host',
        'last_lat',
        'last_lng',
        'last_location_at',
    ];

    protected $casts = [
        'is_host' => 'boolean',
        'last_lat' => 'float',
        'last_lng' => 'float',
        'last_location_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }
}
