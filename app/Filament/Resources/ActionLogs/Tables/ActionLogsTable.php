<?php

namespace App\Filament\Resources\ActionLogs\Tables;

use App\Models\ActionLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ActionLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('session.join_code')
                    ->label('Session')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('player.display_name')
                    ->label('Player')
                    ->placeholder('system'),
                TextColumn::make('type')
                    ->badge()
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(fn (): array => ActionLog::query()
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
