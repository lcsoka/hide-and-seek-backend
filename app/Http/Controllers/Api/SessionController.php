<?php

namespace App\Http\Controllers\Api;

use App\Enums\GameSize;
use App\Enums\SessionStatus;
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
use App\Models\GameEvent;
use App\Models\Question;
use App\Models\Session;
use App\Support\PushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        // A bad/unknown code returns a clean 404 instead of leaking the raw route-binding
        // "No query results for model [App\Models\Session]" exception to the client.
        $session = Session::where('join_code', strtoupper(trim($code)))->first();
        abort_if($session === null, 404, 'Game not found — check the code.');
        $player = $this->factory->join($session, $request->user(), $request->input('display_name'));

        // Announce only a genuinely new player (not a resume from a second device) — so the roster
        // updates live instead of needing a refresh, and the host isn't nudged about a rejoin.
        if ($player->wasRecentlyCreated) {
            GameEventBroadcast::record($session->id, 'PlayerJoined', [
                'player_id' => $player->id,
                'display_name' => $player->display_name,
            ]);
            app(PushNotifier::class)->forLobbyJoin($session, $player);
        }

        return response()->json([
            'player' => new PlayerResource($player),
            'session' => new SessionResource($session->load('players', 'teams')),
        ]);
    }

    public function show(Session $session): SessionResource
    {
        return new SessionResource($session->load('players', 'teams'));
    }

    /** The signed-in user's still-live games (open/running) — so they can rejoin after leaving. */
    public function mySessions(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessions = Session::query()
            ->whereIn('status', [SessionStatus::Open, SessionStatus::Running])
            ->whereHas('players', fn ($q) => $q->where('user_id', $user->id))
            ->with('players')
            ->orderByDesc('last_activity_at')
            ->get()
            ->map(function (Session $session) use ($user) {
                $me = $session->players->firstWhere('user_id', $user->id);

                return [
                    'id' => $session->id,
                    'join_code' => $session->join_code,
                    'city' => $session->config['city']['key'] ?? null,
                    'state' => $session->state,
                    'status' => $session->status,
                    'is_host' => (bool) $me?->is_host,
                    'player_id' => $me?->id,
                    'players_count' => $session->players->count(),
                ];
            });

        return response()->json($sessions);
    }

    public function start(Session $session, GameEngine $engine): JsonResponse
    {
        $player = $engine->playerFor($session, request()->user());
        $session = $engine->submit($session, $player, new Action('start'));

        return response()->json($this->presenter->present($session, $player->refresh()));
    }

    public function state(Session $session): JsonResponse
    {
        // Resolve any elapsed-but-unfired timers (e.g. a pending question past the hider's
        // answer window) so the game never hangs on a dead queue worker.
        $session = $this->engine->catchUpTimers($session);
        $player = $this->engine->playerFor($session, request()->user());

        return response()->json($this->presenter->present($session, $player));
    }

    /**
     * The askable questions for THIS game: the official catalogue plus the host's own custom
     * questions (so a user's saved questions appear only in the games they host).
     */
    public function questions(Session $session): JsonResponse
    {
        $hostUserId = $session->state_data['host_user_id'] ?? null;

        $questions = Question::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $hostUserId))
            ->orderBy('sort')->get()->map(fn (Question $q) => [
                'id' => $q->id,
                'key' => $q->key,
                'category' => $q->category->value,
                'title' => $q->title,
                'prompt' => $q->prompt,
                'parameters' => $q->parameters,
                'reward_draw' => $q->reward_draw,
                'reward_keep' => $q->reward_keep,
            ]);

        return response()->json($questions);
    }

    /**
     * Missed-event catch-up: the durable log of broadcast events after the client's cursor
     * (`?since=<seq>`), scoped to what this player is allowed to see. A reconnecting client
     * replays these (then re-hydrates /state) so nothing that happened while it was
     * backgrounded is lost. Newest events are capped to avoid unbounded replays.
     */
    public function events(Session $session): JsonResponse
    {
        $player = $this->engine->playerFor($session, request()->user());
        $since = (int) request()->query('since', '0');

        $events = GameEvent::query()
            ->where('session_id', $session->id)
            ->where('id', '>', $since)
            ->where(function ($q) use ($player) {
                $q->where('visibility_scope', 'everyone')
                    ->orWhere(function ($q2) use ($player) {
                        $q2->where('visibility_scope', 'player')
                            ->where('visibility_player_id', $player?->id);
                    });
            })
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'type', 'payload']);

        return response()->json([
            'events' => $events->map(fn (GameEvent $e) => [
                'seq' => $e->id,
                'type' => $e->type,
                'payload' => $e->payload ?? [],
            ])->all(),
            // The latest cursor, so a client with nothing to replay still advances its baseline.
            'cursor' => (int) (GameEvent::where('session_id', $session->id)->max('id') ?? $since),
        ]);
    }
}
