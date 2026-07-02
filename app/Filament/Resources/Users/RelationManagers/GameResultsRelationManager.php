<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GameResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'gameResults';

    protected static ?string $title = 'Match history';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('played_at', 'desc')
            ->columns([
                TextColumn::make('played_at')->dateTime()->since()->sortable(),
                TextColumn::make('display_name')->label('Played as'),
                IconColumn::make('won')->boolean()->label('Won'),
                TextColumn::make('hide_time_s')
                    ->label('Hiding time')
                    ->formatStateUsing(fn (int $state): string => sprintf('%d:%02d', intdiv($state, 60), $state % 60)),
                TextColumn::make('players_count')->label('Players')->badge()->color('gray'),
                TextColumn::make('session.join_code')->label('Session')->badge()->color('gray')->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
