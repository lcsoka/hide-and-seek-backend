<?php

namespace App\Filament\Resources\Questions\Schemas;

use App\Enums\QuestionCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QuestionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        Select::make('category')
                            ->options(QuestionCategory::class)
                            ->required(),
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Short label, e.g. "Radar — 1 mile".'),
                        Textarea::make('prompt')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('The askable text or template shown to players.'),
                        TextInput::make('reward_draw')
                            ->numeric()
                            ->default(0)
                            ->label('Cards drawn'),
                        TextInput::make('reward_keep')
                            ->numeric()
                            ->default(0)
                            ->label('Cards kept'),
                    ]),

                Section::make('Options & flags')
                    ->columns(3)
                    ->schema([
                        Toggle::make('is_active')->default(true),
                        Toggle::make('is_custom')
                            ->helperText('On for house-made questions.'),
                        TextInput::make('sort')->numeric()->default(0),
                        Textarea::make('parameters')
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('Optional JSON, e.g. distance/radius options.')
                            ->rules(['nullable', 'json'])
                            ->formatStateUsing(fn ($state) => filled($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null),
                    ]),
            ]);
    }
}
