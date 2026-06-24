<?php

namespace App\Game;

use App\Enums\GameSize;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SessionFactory
{
    public function __construct(private readonly GameModeRegistry $modes) {}

    /**
     * Create a session for the given host, in a Hungarian city at a map size,
     * and add the host as the first (host) player.
     *
     * @param  array<string, mixed>  $overrides  Config overrides merged over the mode defaults.
     */
    public function create(User $host, string $modeKey, string $cityKey, GameSize $size, array $overrides = [], ?string $displayName = null): Session
    {
        $mode = $this->modes->make($modeKey);
        $city = config("game.cities.{$cityKey}");

        if ($city === null) {
            throw new InvalidArgumentException("Unknown city [{$cityKey}].");
        }

        $config = array_merge(
            $mode->defaultConfig($size),
            ['city' => array_merge(['key' => $cityKey], $city)],
            $overrides,
        );

        $session = Session::create([
            'join_code' => $this->uniqueJoinCode(),
            'game_mode' => $modeKey,
            'state' => $mode->initialState(),
            'state_data' => $mode->initialStateData(),
            'config' => $config,
            'status' => 'open',
        ]);

        $player = $this->join($session, $host, $displayName ?? $host->name, isHost: true);
        $session->update(['host_player_id' => $player->id]);

        return $session->refresh();
    }

    public function join(Session $session, User $user, string $displayName, bool $isHost = false): Player
    {
        return $session->players()->create([
            'user_id' => $user->id,
            'display_name' => $displayName,
            'is_host' => $isHost,
        ]);
    }

    private function uniqueJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Session::where('join_code', $code)->exists());

        return $code;
    }
}
