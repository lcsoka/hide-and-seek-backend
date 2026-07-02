<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A finished game's outcome for one user — the durable basis for their history + stats. */
class GameResult extends Model
{
    public $timestamps = false; // only played_at matters (append-only)

    protected $fillable = ['user_id', 'session_id', 'display_name', 'hide_time_s', 'won', 'players_count', 'played_at'];

    protected $casts = [
        'won' => 'boolean',
        'played_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
