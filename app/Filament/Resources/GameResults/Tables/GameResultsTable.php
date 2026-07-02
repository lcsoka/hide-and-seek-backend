<?php

namespace App\Filament\Resources\GameResults\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Models\GameResult;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class GameResultsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('played_at', 'desc')
            ->columns([
                TextColumn::make('played_at')->dateTime()->since()->sortable(),
                TextColumn::make('display_name')
                    ->label('Player')
                    ->weight('bold')
                    ->description(fn (GameResult $record): ?string => $record->user?->email)
                    ->searchable()
                    ->url(fn (GameResult $record): ?string => $record->user_id ? UserResource::getUrl('view', ['record' => $record->user_id]) : null),
                IconColumn::make('won')->boolean()->label('Won')->sortable(),
                TextColumn::make('hide_time_s')
                    ->label('Hiding time')
                    ->formatStateUsing(fn (int $state): string => sprintf('%d:%02d', intdiv($state, 60), $state % 60))
                    ->sortable(),
                TextColumn::make('players_count')->label('Players')->badge()->color('gray')->sortable(),
                TextColumn::make('session.join_code')->label('Session')->badge()->color('gray')->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('won')->label('Winners'),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
