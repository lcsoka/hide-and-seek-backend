<?php

namespace App\Filament\Resources\Sessions\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class SessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('join_code')
                    ->required(),
                TextInput::make('game_mode')
                    ->required(),
                TextInput::make('state')
                    ->required()
                    ->default('lobby'),
                Textarea::make('state_data')
                    ->columnSpanFull(),
                Textarea::make('config')
                    ->columnSpanFull(),
                TextInput::make('host_player_id')
                    ->numeric(),
                TextInput::make('status')
                    ->required()
                    ->default('open'),
            ]);
    }
}
