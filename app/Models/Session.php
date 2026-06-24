<?php

namespace App\Models;

use App\Enums\GameMode;
use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Session extends Model
{
    use HasUuids;

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
        'status' => SessionStatus::class,
        'game_mode' => GameMode::class,
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
