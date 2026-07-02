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

        // Let the mode react to the new position (e.g. the endgame proximity trigger).
        $this->engine->observeLocation($session, $player);

        // Broadcast SEEKER movement so others (incl. the hider) see it live. The hider's
        // own position is concealed from seekers, so it is never broadcast. Throttled to
        // ~once per 2s per player via an atomic Cache::add (returns true only if the key was
        // absent) so two near-simultaneous pings can't both fire the event.
        if ($player->role === 'seeker' && Cache::add("moved:{$player->id}", true, now()->addSeconds(2))) {
            GameEventBroadcast::dispatch($session->id, 'PlayerMoved', ['player_id' => $player->id, 'lat' => $lat, 'lng' => $lng]);
        }

        return response()->noContent();
    }
}
