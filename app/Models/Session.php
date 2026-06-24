<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    /**
     * Backed by `game_sessions` to avoid colliding with Laravel's framework
     * `sessions` table (used by the database session driver).
     */
    protected $table = 'game_sessions';

    protected $fillable = [
        'join_code',
        'game_mode',
        'state',
        'state_data',
        'config',
        'host_player_id',
        'status',
    ];

    protected $casts = [
        'state_data' => 'array',
        'config' => 'array',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'host_player_id');
    }
}
