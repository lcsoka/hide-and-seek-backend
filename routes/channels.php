<?php

use App\Models\Session;
use Illuminate\Support\Facades\Broadcast;

/*
 | Presence channel for a session: membership = who is connected, and all
 | gameplay events broadcast here. Only participants (players) may join.
 */
Broadcast::channel('session.{sessionId}', function ($user, string $sessionId) {
    $player = Session::query()
        ->whereKey($sessionId)
        ->first()
        ?->players()
        ->where('user_id', $user->id)
        ->first();

    return $player
        ? ['id' => $user->id, 'player_id' => $player->id, 'display_name' => $player->display_name]
        : null;
});
