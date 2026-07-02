<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded broadcast event, kept so a reconnecting client can replay what it missed.
 * Append-only: only created_at (no updated_at).
 */
class GameEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['session_id', 'type', 'payload', 'visibility_scope', 'visibility_player_id'];

    protected $casts = [
        'payload' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
