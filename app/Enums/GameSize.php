<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GameSize: string implements HasLabel
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    public function getLabel(): ?string
    {
        return __("enums.game_size.{$this->value}");
    }

    /** Play radius around the chosen city's centre, in kilometres. */
    public function playRadiusKm(): float
    {
        return match ($this) {
            self::Small => 3.0,
            self::Medium => 25.0,
            self::Large => 100.0,
        };
    }

    /** Default hiding-phase time limit, in seconds. */
    public function hidingTimeLimitSeconds(): int
    {
        return match ($this) {
            self::Small => 900,    // 15 min
            self::Medium => 1800,  // 30 min
            self::Large => 3600,   // 60 min
        };
    }

    /** Base time-bonus unit (used by scoring/round bonuses), in seconds. */
    public function timeBonusSeconds(): int
    {
        return match ($this) {
            self::Small => 300,    // 5 min
            self::Medium => 900,   // 15 min
            self::Large => 1800,   // 30 min
        };
    }
}
