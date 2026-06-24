<?php

namespace App\Filament\Resources\ActionLogs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ActionLogForm
{
    /**
     * Read-only: this schema only backs the View modal (no create/edit pages).
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('session_id')
                    ->relationship('session', 'join_code')
                    ->disabled(),
                Select::make('player_id')
                    ->relationship('player', 'display_name')
                    ->placeholder('system')
                    ->disabled(),
                TextInput::make('type')
                    ->disabled(),
                TextInput::make('created_at')
                    ->disabled(),
                Textarea::make('payload')
                    ->rows(10)
                    ->columnSpanFull()
                    ->disabled()
                    ->formatStateUsing(fn ($state) => filled($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null),
            ]);
    }
}
