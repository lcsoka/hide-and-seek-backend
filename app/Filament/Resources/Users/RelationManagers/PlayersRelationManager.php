<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlayersRelationManager extends RelationManager
{
    protected static string $relationship = 'players';

    protected static ?string $title = 'Sessions joined';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->since()->sortable(),
                TextColumn::make('display_name')->label('Name'),
                TextColumn::make('session.join_code')->label('Session')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('role')->badge()->placeholder('—'),
                IconColumn::make('is_host')->boolean()->label('Host'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
