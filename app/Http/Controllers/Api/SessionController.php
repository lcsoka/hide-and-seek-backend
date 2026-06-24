<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameSize;
use App\Game\SessionFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\JoinSessionRequest;
use App\Http\Requests\StoreSessionRequest;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\SessionResource;
use App\Models\Session;
use Illuminate\Http\JsonResponse;

class SessionController extends Controller
{
    public function __construct(private readonly SessionFactory $factory) {}

    public function store(StoreSessionRequest $request): JsonResponse
    {
        $session = $this->factory->create(
            host: $request->user(),
            modeKey: $request->input('game_mode', config('game.default_mode')),
            cityKey: $request->input('city'),
            size: GameSize::from($request->input('game_size')),
            overrides: $request->input('config', []),
            displayName: $request->input('display_name'),
        );

        return (new SessionResource($session->load('players', 'teams')))
            ->response()
            ->setStatusCode(201);
    }

    public function join(JoinSessionRequest $request, string $code): JsonResponse
    {
        $session = Session::where('join_code', strtoupper($code))->firstOrFail();
        $player = $this->factory->join($session, $request->user(), $request->input('display_name'));

        return response()->json([
            'player' => new PlayerResource($player),
            'session' => new SessionResource($session->load('players', 'teams')),
        ]);
    }

    public function show(Session $session): SessionResource
    {
        return new SessionResource($session->load('players', 'teams'));
    }

    public function state(Session $session): JsonResponse
    {
        $session->load('players', 'teams');

        return response()->json([
            'session_id' => $session->id,
            'game_mode' => $session->game_mode?->value,
            'state' => $session->state,
            'status' => $session->status?->value,
            'round' => $session->state_data['round'] ?? 0,
            'config' => $session->config,
            'players' => PlayerResource::collection($session->players),
            'teams' => $session->teams->map(fn ($team) => [
                'id' => $team->id, 'name' => $team->name, 'color' => $team->color,
            ]),
            // Populated once the action/visibility engine lands.
            'available_actions' => [],
            'timers' => [],
        ]);
    }
}
