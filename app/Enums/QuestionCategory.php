<?php

namespace App\Enums;

use Filament\Support\Colors\Color;
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
        return __("enums.question_category.{$this->value}");
    }

    public function getColor(): string|array|null
    {
        // Brand palette shared with the web app's category badges.
        return match ($this) {
            self::Matching => Color::hex('#1E2A44'),
            self::Measuring => Color::hex('#5E9ED0'),
            self::Radar => Color::hex('#EE8A3B'),
            self::Thermometer => Color::hex('#D9534F'),
            self::Photo => Color::hex('#4FA65B'),
            self::Tentacles => Color::hex('#8E76B4'),
        };
    }
}
