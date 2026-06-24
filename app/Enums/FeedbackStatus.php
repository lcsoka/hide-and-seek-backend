<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FeedbackStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Triaged = 'triaged';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function getLabel(): ?string
    {
        return __("enums.feedback_status.{$this->value}");
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Open => 'warning',
            self::Triaged => 'info',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
        };
    }
}
