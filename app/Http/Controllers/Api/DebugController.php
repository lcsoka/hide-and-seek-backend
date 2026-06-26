<?php

namespace App\Http\Controllers\Api;

use App\Game\GameEngine;
use App\Game\GameStatePresenter;
use App\Game\Support\Action;
use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Developer/debug API (gated by EnsureDebugAccess). Lets one person exercise the
 * whole game without fielding phones: god view, act as any player, spoof GPS,
 * simulate players, force state, and fire timers.
 */
class DebugController extends Controller
{
    public function __construct(
        private readonly GameEngine $engine,
        private readonly GameStatePresenter $presenter,
    ) {}

    /** Unfiltered god view — bypasses locationVisibility (sees the hider too). */
    public function state(Session $session): JsonResponse
    {
        return response()->json($this->godView($session));
    }

    /** Resolve a join code to the god view, so the dev spectate flow accepts a code OR an id. */
    public function resolveCode(string $code): JsonResponse
    {
        $session = Session::where('join_code', strtoupper($code))->firstOrFail();

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

    /**
     * Mint a bearer token for any player in the session, so a dev can open that player's
     * real (filtered) view in another window/iframe — the spectate-existing-game flow.
     * Bots created without a user get a throwaway guest user first.
     */
    public function mintToken(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate(['player_id' => ['required', 'uuid']]);
        $player = $session->players()->findOrFail($data['player_id']);

        $user = $player->user;
        if ($user === null) {
            $user = User::create(['name' => $player->display_name]);
            $player->update(['user_id' => $user->id]);
        }

        return response()->json([
            'player_id' => $player->id,
            'token' => $user->createToken('spectate')->plainTextToken,
        ]);
    }

    public function location(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'uuid'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $player = $session->players()->findOrFail($data['player_id']);
        $player->update(['last_lat' => $data['lat'], 'last_lng' => $data['lng'], 'last_location_at' => now()]);
        $this->engine->observeLocation($session->refresh(), $player->refresh());

        return response()->json($this->godView($session->refresh()));
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
        $history = $this->presenter->history($session);

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
            'questions' => $history['questions'],
            'curses' => $history['curses'],
            // The full step audit trail, for the admin replay timeline.
            'action_logs' => ActionLog::query()->where('session_id', $session->id)->orderBy('created_at')->get()->map(fn (ActionLog $l) => [
                'type' => $l->type,
                'player_id' => $l->player_id,
                'payload' => $l->payload,
                'at' => $l->created_at?->timestamp,
            ])->all(),
        ];
    }
}
