<?php

namespace App\Filament\Resources\Sessions\Tables;

use App\Enums\GameMode;
use App\Enums\SessionStatus;
use App\Models\Session;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Eager-load the host player so the "Host" column isn't an N+1 across the list.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('host'))
            ->columns([
                TextColumn::make('join_code')
                    ->label('Join code')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('game_mode')
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('state')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('players_count')
                    ->label('Players')
                    ->counts('players')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('host.display_name')
                    ->label('Host')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SessionStatus::class),
                SelectFilter::make('game_mode')
                    ->options(GameMode::class),
            ])
            ->recordActions([
                Action::make('replay')
                    ->label('Replay')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->url(fn (Session $record): string => rtrim(config('app.web_url'), '/').'/replay/s/'.$record->getKey())
                    ->openUrlInNewTab(),
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
