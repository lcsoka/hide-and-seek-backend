<?php

namespace App\Filament\Resources\Players\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlayerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Player')
                    ->columns(2)
                    ->schema([
                        Select::make('session_id')
                            ->relationship('session', 'join_code')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('display_name')
                            ->required()
                            ->maxLength(255),
                        Select::make('team_id')
                            ->relationship('team', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('No team'),
                        TextInput::make('role')
                            ->helperText('Mode-defined, e.g. hider / seeker.'),
                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->placeholder('Guest'),
                        Toggle::make('is_host')
                            ->inline(false),
                    ]),

                Section::make('Last known location')
                    ->columns(3)
                    ->schema([
                        TextInput::make('last_lat')
                            ->numeric()
                            ->label('Latitude'),
                        TextInput::make('last_lng')
                            ->numeric()
                            ->label('Longitude'),
                        DateTimePicker::make('last_location_at')
                            ->seconds(false),
                    ]),
            ]);
    }
}
