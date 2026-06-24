<?php

namespace App\Http\Controllers\Api;

use App\Game\GameEngine;
use App\Game\GameStatePresenter;
use App\Game\Support\Action;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitActionRequest;
use App\Models\Session;
use Illuminate\Http\JsonResponse;

class ActionController extends Controller
{
    public function __construct(
        private readonly GameEngine $engine,
        private readonly GameStatePresenter $presenter,
    ) {}

    public function store(SubmitActionRequest $request, Session $session): JsonResponse
    {
        $player = $this->engine->playerFor($session, $request->user());

        $session = $this->engine->submit(
            $session,
            $player,
            new Action($request->input('type'), $request->input('payload', [])),
        );

        return response()->json($this->presenter->present($session, $player->refresh()));
    }
}
