<?php

namespace App\Http\Controllers\Api;

use App\Events\GameEventBroadcast;
use App\Game\GameEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocationRequest;
use App\Models\Session;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class LocationController extends Controller
{
    public function __construct(private readonly GameEngine $engine) {}

    public function store(LocationRequest $request, Session $session): Response
    {
        $player = $this->engine->playerFor($session, $request->user());
        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');

        $player->update(['last_lat' => $lat, 'last_lng' => $lng, 'last_location_at' => now()]);

        // Location pings count as activity (keeps the session off the abandonment list).
        $session->update(['last_activity_at' => now()]);

        // Broadcast SEEKER movement so others (incl. the hider) see it live. The hider's
        // own position is concealed from seekers, so it is never broadcast. Throttled to
        // ~once per 2s per player to keep the event volume sane.
        if ($player->role === 'seeker' && ! Cache::get("moved:{$player->id}")) {
            Cache::put("moved:{$player->id}", true, now()->addSeconds(2));
            GameEventBroadcast::dispatch($session->id, 'PlayerMoved', ['player_id' => $player->id, 'lat' => $lat, 'lng' => $lng]);
        }

        return response()->noContent();
    }
}
