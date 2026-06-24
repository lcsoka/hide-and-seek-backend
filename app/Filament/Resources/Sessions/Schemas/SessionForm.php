<?php

namespace App\Filament\Resources\Sessions\Schemas;

use App\Enums\GameMode;
use App\Enums\SessionStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(3)
                    ->schema([
                        TextInput::make('join_code')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->default(fn () => strtoupper(Str::random(6)))
                            ->helperText('Short code players use to join.'),
                        Select::make('game_mode')
                            ->options(GameMode::class)
                            ->default(GameMode::HideAndSeek->value)
                            ->required(),
                        Select::make('status')
                            ->options(SessionStatus::class)
                            ->default(SessionStatus::Open->value)
                            ->required(),
                    ]),

                Section::make('State machine')
                    ->columns(2)
                    ->schema([
                        TextInput::make('state')
                            ->required()
                            ->default('lobby')
                            ->helperText('Current state-machine node, e.g. lobby, hiding, seeking.'),
                        Select::make('host_player_id')
                            ->label('Host')
                            ->relationship('host', 'display_name')
                            ->searchable()
                            ->preload()
                            ->helperText('The hosting player (set once players exist).'),
                    ]),

                Section::make('Mode data (JSON)')
                    ->description('Resolved GameModeConfig and the mode-owned state blob. Edited as raw JSON.')
                    ->collapsible()
                    ->schema([
                        self::jsonField('config', 'Resolved GameModeConfig for this session.'),
                        self::jsonField('state_data', 'Mode-owned state (decks, scores, hider zone, curses…).'),
                    ]),
            ]);
    }

    private static function jsonField(string $name, string $helper): Textarea
    {
        return Textarea::make($name)
            ->helperText($helper)
            ->rows(8)
            ->columnSpanFull()
            ->rules(['nullable', 'json'])
            ->formatStateUsing(fn ($state) => filled($state)
                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null)
            ->dehydrateStateUsing(fn ($state) => filled($state)
                ? json_decode($state, true)
                : null);
    }
}
