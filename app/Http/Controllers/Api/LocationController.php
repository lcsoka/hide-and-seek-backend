<?php

namespace App\Http\Controllers\Api;

use App\Game\GameEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocationRequest;
use App\Models\Session;
use Illuminate\Http\Response;

class LocationController extends Controller
{
    public function __construct(private readonly GameEngine $engine) {}

    public function store(LocationRequest $request, Session $session): Response
    {
        $player = $this->engine->playerFor($session, $request->user());

        $player->update([
            'last_lat' => $request->input('lat'),
            'last_lng' => $request->input('lng'),
            'last_location_at' => now(),
        ]);

        // Location pings count as activity (keeps the session off the abandonment list).
        $session->update(['last_activity_at' => now()]);

        return response()->noContent();
    }
}
