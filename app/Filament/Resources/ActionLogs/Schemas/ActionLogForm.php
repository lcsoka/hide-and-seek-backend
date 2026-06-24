<?php

namespace App\Filament\Resources\ActionLogs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ActionLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('session_id')
                    ->relationship('session', 'id')
                    ->required(),
                Select::make('player_id')
                    ->relationship('player', 'id'),
                TextInput::make('type')
                    ->required(),
                Textarea::make('payload')
                    ->columnSpanFull(),
            ]);
    }
}
