<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum QuestionCategory: string implements HasColor, HasLabel
{
    case Matching = 'matching';
    case Measuring = 'measuring';
    case Radar = 'radar';
    case Thermometer = 'thermometer';
    case Photo = 'photo';
    case Tentacles = 'tentacles';

    public function getLabel(): ?string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Matching => 'info',
            self::Measuring => 'warning',
            self::Radar => 'success',
            self::Thermometer => 'danger',
            self::Photo => 'gray',
            self::Tentacles => 'primary',
        };
    }
}
