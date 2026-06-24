<?php

namespace App\Http\Controllers\Api;

use App\Game\GameEngine;
use App\Game\Support\Action;
use App\Http\Controllers\Controller;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Developer/debug API (gated by EnsureDebugAccess). Lets one person exercise the
 * whole game without fielding phones: god view, act as any player, spoof GPS,
 * simulate players, force state, and fire timers.
 */
class DebugController extends Controller
{
    public function __construct(private readonly GameEngine $engine) {}

    /** Unfiltered god view — bypasses locationVisibility (sees the hider too). */
    public function state(Session $session): JsonResponse
    {
        return response()->json($this->godView($session));
    }

    public function actAs(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'uuid'],
            'type' => ['required', 'string'],
            'payload' => ['nullable', 'array'],
        ]);

        $player = $session->players()->findOrFail($data['player_id']);
        $session = $this->engine->submit($session, $player, new Action($data['type'], $data['payload'] ?? []));

        return response()->json($this->godView($session));
    }

    public function location(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'uuid'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $session->players()->findOrFail($data['player_id'])
            ->update(['last_lat' => $data['lat'], 'last_lng' => $data['lng'], 'last_location_at' => now()]);

        return response()->json($this->godView($session));
    }

    public function seedPlayers(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate(['count' => ['required', 'integer', 'min:1', 'max:20']]);

        $existing = $session->players()->count();
        for ($i = 1; $i <= $data['count']; $i++) {
            $session->players()->create(['display_name' => 'Bot '.($existing + $i)]);
        }

        return response()->json($this->godView($session->refresh()));
    }

    public function forceState(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string'],
            'state_data' => ['nullable', 'array'],
        ]);

        $session->update(array_filter([
            'state' => $data['state'],
            'state_data' => $data['state_data'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json($this->godView($session));
    }

    public function expireTimer(Session $session, string $key): JsonResponse
    {
        $this->engine->fireTimer($session, $key, $session->state_data[$key] ?? null);

        return response()->json($this->godView($session->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function godView(Session $session): array
    {
        $session->load('players', 'teams');

        return [
            'session_id' => $session->id,
            'state' => $session->state,
            'status' => $session->status?->value,
            'round' => $session->state_data['round'] ?? 0,
            'config' => $session->config,
            'state_data' => $session->state_data, // unfiltered (hider_id, pending truths, etc.)
            'players' => $session->players->map(fn ($p) => [
                'id' => $p->id, 'display_name' => $p->display_name, 'role' => $p->role,
                'is_host' => $p->is_host, 'team_id' => $p->team_id, 'lat' => $p->last_lat, 'lng' => $p->last_lng,
            ]),
            'teams' => $session->teams->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color]),
        ];
    }
}
