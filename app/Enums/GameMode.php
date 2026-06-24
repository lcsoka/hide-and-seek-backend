<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum GameMode: string implements HasLabel
{
    case HideAndSeek = 'hide_and_seek';
    // Future modes (Tag, Capture the Flag…) get a case here as they ship.

    public function getLabel(): ?string
    {
        return __("enums.game_mode.{$this->value}");
    }
}
