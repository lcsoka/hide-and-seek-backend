<?php

namespace App\Game;

use App\Enums\GameSize;
use App\Models\City;
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
    public function create(User $host, string $modeKey, string $cityKey, ?GameSize $size = null, array $overrides = [], ?string $displayName = null): Session
    {
        $mode = $this->modes->make($modeKey);
        $city = City::where('key', $cityKey)->where('is_active', true)->first();

        if ($city === null) {
            throw new InvalidArgumentException("Unknown city [{$cityKey}].");
        }

        // The play size is tied to the city (admin-set) — the host no longer picks it. A caller
        // (e.g. a dev command) may still force one.
        $size ??= $city->default_size;

        $config = array_merge(
            $mode->defaultConfig($size),
            ['city' => ['key' => $city->key, 'name' => $city->name, 'lat' => $city->lat, 'lng' => $city->lng]],
            $overrides,
        );

        // Units are always metric (no client choice), and hiding spots can only use transit modes
        // that actually exist in this city — clamp whatever was requested to the city's set.
        $config['units'] = 'metric';
        $requested = array_values(array_intersect((array) ($config['transit_modes'] ?? []), $city->available_modes));
        $config['transit_modes'] = $requested ?: $city->available_modes;

        // The host may curate the deck in the wizard (a list of card ids to keep). It drives the
        // draw pool, so it lives in state_data (like host_user_id) rather than the public config.
        $deckCards = isset($config['deck_cards']) && is_array($config['deck_cards'])
            ? array_values(array_filter(array_map('strval', $config['deck_cards'])))
            : null;
        unset($config['deck_cards']);

        $stateData = array_merge($mode->initialStateData(), ['host_user_id' => $host->id]);
        if ($deckCards) {
            $stateData['deck_cards'] = $deckCards;
        }

        $session = Session::create([
            'join_code' => $this->uniqueJoinCode(),
            'game_mode' => $modeKey,
            'state' => $mode->initialState(),
            // Remember the host's user so their own custom curses join this game's deck.
            'state_data' => $stateData,
            'config' => $config,
            'status' => 'open',
        ]);

        $player = $this->join($session, $host, $displayName ?? $host->name, isHost: true);
        $session->update(['host_player_id' => $player->id]);

        return $session->refresh();
    }

    public function join(Session $session, User $user, string $displayName, bool $isHost = false): Player
    {
        // Idempotent by user: rejoining (a second device, or a reconnect) resumes the SAME player
        // instead of spawning a duplicate. `wasRecentlyCreated` lets callers tell a join from a resume.
        $existing = $session->players()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }

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
