<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FeedbackType: string implements HasColor, HasLabel
{
    case Suggestion = 'suggestion';
    case Bug = 'bug';

    public function getLabel(): ?string
    {
        return __("enums.feedback_type.{$this->value}");
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Suggestion => 'info',
            self::Bug => 'danger',
        };
    }
}
