<?php

namespace App\Http\Controllers\Api;

use App\Game\Geo\OverpassClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side, cached Overpass proxy. The web app routes ALL of its OpenStreetMap queries
 * here instead of calling the public Overpass API directly — one shared 6h cache (fast
 * repeat requests), no client-side mirror juggling or rate limits, and OSM is hit once per
 * unique query across every player.
 */
class GeoController extends Controller
{
    public function overpass(Request $request, OverpassClient $client): JsonResponse
    {
        $data = $request->validate([
            'ql' => ['required', 'string', 'max:8000'],
        ]);

        $result = $client->run($data['ql']);

        // On total failure return an empty result set (not an error) so the client's
        // osmtogeojson yields no features and the game falls back gracefully.
        return $result === null
            ? response()->json(['elements' => []], 502)
            : response()->json($result);
    }
}
