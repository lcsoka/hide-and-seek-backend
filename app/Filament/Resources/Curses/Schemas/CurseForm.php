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
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('cost')
                            ->maxLength(255)
                            ->helperText('Casting cost, e.g. "Discard 2 cards".'),
                        TextInput::make('sort')->numeric()->default(0),
                        Textarea::make('description')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull()
                            ->label('Effect'),
                    ]),

                Section::make('Flags')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')->default(true),
                        Toggle::make('is_custom')
                            ->helperText('On for house-made curses.'),
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
