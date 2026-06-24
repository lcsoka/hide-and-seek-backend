<?php

namespace App\Game\Modes\HideAndSeek;

use App\Enums\GameSize;
use App\Game\Contracts\GameMode;

class HideAndSeekMode implements GameMode
{
    public function key(): string
    {
        return 'hide_and_seek';
    }

    public function displayName(): string
    {
        return __('enums.game_mode.hide_and_seek');
    }

    public function defaultConfig(GameSize $size): array
    {
        return [
            'game_size' => $size->value,
            'rounds' => 3,
            'play_radius_km' => $size->playRadiusKm(),
            'hiding_time_limit_s' => $size->hidingTimeLimitSeconds(),
            'endgame_radius_m' => 500,
            'question_cooldown_s' => 300,
            'time_bonus_s' => $size->timeBonusSeconds(),
        ];
    }

    public function initialState(): string
    {
        return 'lobby';
    }

    public function initialStateData(): array
    {
        return [
            'round' => 0,
            'scores' => [],
        ];
    }
}
