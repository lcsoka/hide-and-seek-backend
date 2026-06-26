<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameSize;
use App\Events\GameEventBroadcast;
use App\Game\GameEngine;
use App\Game\GameStatePresenter;
use App\Game\SessionFactory;
use App\Game\Support\Action;
use App\Http\Controllers\Controller;
use App\Http\Requests\JoinSessionRequest;
use App\Http\Requests\StoreSessionRequest;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\SessionResource;
use App\Models\Session;
use Illuminate\Http\JsonResponse;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionFactory $factory,
        private readonly GameEngine $engine,
        private readonly GameStatePresenter $presenter,
    ) {}

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

        // Tell everyone already in the session (esp. the host's lobby) about the new player,
        // so the roster updates live instead of needing a refresh.
        GameEventBroadcast::dispatch($session->id, 'PlayerJoined', [
            'player_id' => $player->id,
            'display_name' => $player->display_name,
        ]);

        return response()->json([
            'player' => new PlayerResource($player),
            'session' => new SessionResource($session->load('players', 'teams')),
        ]);
    }

    public function show(Session $session): SessionResource
    {
        return new SessionResource($session->load('players', 'teams'));
    }

    public function start(Session $session, GameEngine $engine): JsonResponse
    {
        $player = $engine->playerFor($session, request()->user());
        $session = $engine->submit($session, $player, new Action('start'));

        return response()->json($this->presenter->present($session, $player->refresh()));
    }

    public function state(Session $session): JsonResponse
    {
        $player = $this->engine->playerFor($session, request()->user());

        return response()->json($this->presenter->present($session, $player));
    }
}
