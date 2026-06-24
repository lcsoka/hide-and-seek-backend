<?php

namespace App\Filament\Resources\Players\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlayersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('display_name')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('session.join_code')
                    ->label('Session')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->placeholder('—'),
                IconColumn::make('is_host')
                    ->label('Host')
                    ->boolean(),
                TextColumn::make('location')
                    ->state(fn ($record) => ($record->last_lat !== null && $record->last_lng !== null)
                        ? sprintf('%.5f, %.5f', $record->last_lat, $record->last_lng)
                        : null)
                    ->placeholder('—'),
                TextColumn::make('last_location_at')
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('session')
                    ->relationship('session', 'join_code'),
                SelectFilter::make('is_host')
                    ->options([1 => 'Host', 0 => 'Player']),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
