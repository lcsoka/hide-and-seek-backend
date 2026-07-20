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
        'last_accuracy_m',
        'last_location_at',
    ];

    protected $casts = [
        'is_host' => 'boolean',
        'last_lat' => 'float',
        'last_lng' => 'float',
        'last_accuracy_m' => 'float',
        'last_location_at' => 'datetime',
    ];

    /**
     * Whether this player's last fix is good enough to decide anything with.
     *
     * A phone that loses satellite lock silently falls back to a wifi/cell-tower fix that can be
     * hundreds of metres out, and taking that at face value corrupts the game rather than merely
     * blurring it: it drags the hider's committed spot across their zone, moves the reference
     * point a cut is drawn from, and can fire the endgame trigger from the next street over.
     * Callers use this in place of a bare "do we have coordinates?" check.
     *
     * A null accuracy means the reading predates this check or came from the dev harness, so it
     * is trusted — only a fix that reports its own poor quality is rejected.
     */
    public function hasReliableFix(): bool
    {
        if ($this->last_lat === null || $this->last_lng === null) {
            return false;
        }

        return $this->last_accuracy_m === null
            || $this->last_accuracy_m <= (float) config('game.location.max_accuracy_m', 50);
    }

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
