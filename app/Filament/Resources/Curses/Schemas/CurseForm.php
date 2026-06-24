<?php

namespace App\Filament\Resources\Curses\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CurseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Magyar (HU)')
                    ->schema([
                        TextInput::make('name_hu')->label('Name (HU)')->required()->maxLength(255),
                        TextInput::make('cost_hu')->label('Cost (HU)')->maxLength(255)
                            ->helperText('Casting cost, e.g. "2 kártya eldobása".'),
                        Textarea::make('description_hu')->label('Effect (HU)')->required()->rows(3),
                    ]),

                Section::make('English (EN)')
                    ->schema([
                        TextInput::make('name_en')->label('Name (EN)')->required()->maxLength(255),
                        TextInput::make('cost_en')->label('Cost (EN)')->maxLength(255),
                        Textarea::make('description_en')->label('Effect (EN)')->required()->rows(3),
                    ]),

                Section::make('Flags')
                    ->columns(3)
                    ->schema([
                        Toggle::make('is_active')->default(true),
                        Toggle::make('is_custom')->helperText('On for house-made curses.'),
                        TextInput::make('sort')->numeric()->default(0),
                        Textarea::make('parameters')
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Optional JSON for structured effects.')
                            ->rules(['nullable', 'json'])
                            ->formatStateUsing(fn ($state) => filled($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null),
                    ]),
            ]);
    }
}
