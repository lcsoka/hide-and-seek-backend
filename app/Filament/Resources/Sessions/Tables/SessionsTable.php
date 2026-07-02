<?php

namespace App\Filament\Resources\Sessions\Tables;

use App\Enums\GameMode;
use App\Enums\SessionStatus;
use App\Filament\Resources\Sessions\SessionResource;
use App\Models\Session;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
                TextColumn::make('city')
                    ->label('City')
                    ->state(fn (Session $record): ?string => is_array($record->config) ? ($record->config['city'] ?? null) : null)
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—')
                    ->badge()
                    ->color('gray'),
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
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (Session $record): string => self::duration($record))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SessionStatus::class),
                SelectFilter::make('game_mode')
                    ->options(GameMode::class),
            ])
            ->recordActions([
                Action::make('state')
                    ->label('State')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('info')
                    ->url(fn (Session $record): string => SessionResource::getUrl('state', ['record' => $record])),
                Action::make('replay')
                    ->label('Replay')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->url(fn (Session $record): string => SessionResource::getUrl('replay', ['record' => $record]))
                    ->openUrlInNewTab(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('forceEnd')
                        ->label('Force-end (abandon)')
                        ->icon('heroicon-o-flag')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Mark the selected live sessions as abandoned and stamp them ended now.')
                        ->action(fn (Collection $records) => $records
                            ->whereIn('status', [SessionStatus::Open, SessionStatus::Running])
                            ->each->update(['status' => SessionStatus::Abandoned, 'ended_at' => now()])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Wall-clock span of a game: created → ended (or "now" while live). */
    private static function duration(Session $record): string
    {
        $end = $record->ended_at ?? now();
        $seconds = max(0, $record->created_at?->diffInSeconds($end) ?? 0);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }
}
