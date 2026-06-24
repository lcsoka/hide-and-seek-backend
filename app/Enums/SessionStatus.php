<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SessionStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Running = 'running';
    case Finished = 'finished';
    case Abandoned = 'abandoned';

    public function getLabel(): ?string
    {
        return __("enums.session_status.{$this->value}");
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Open => 'gray',
            self::Running => 'success',
            self::Finished => 'info',
            self::Abandoned => 'danger',
        };
    }
}
