<?php

namespace App\Filament\Resources\Players\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlayerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('session_id')
                    ->relationship('session', 'id')
                    ->required(),
                Select::make('user_id')
                    ->relationship('user', 'name'),
                TextInput::make('display_name')
                    ->required(),
                Select::make('team_id')
                    ->relationship('team', 'name'),
                TextInput::make('role'),
                Toggle::make('is_host')
                    ->required(),
                TextInput::make('last_lat')
                    ->numeric(),
                TextInput::make('last_lng')
                    ->numeric(),
                DateTimePicker::make('last_location_at'),
            ]);
    }
}
