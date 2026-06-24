<?php

namespace App\Game;

use App\Game\Contracts\GameMode;
use InvalidArgumentException;

class GameModeRegistry
{
    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('game.modes', []));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, config('game.modes', []));
    }

    public function make(string $key): GameMode
    {
        $modes = config('game.modes', []);

        if (! isset($modes[$key])) {
            throw new InvalidArgumentException("Unknown game mode [{$key}].");
        }

        return app($modes[$key]);
    }
}
