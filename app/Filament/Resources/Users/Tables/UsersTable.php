<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'gameResults',
                'players',
                'gameResults as wins_count' => fn (Builder $q) => $q->where('won', true),
            ])->withMax('gameResults', 'played_at'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('avatar')
                    ->circular()
                    ->size(36)
                    ->defaultImageUrl(fn (User $record): string => 'https://ui-avatars.com/api/?background=e11d48&color=fff&name='.urlencode($record->name ?? '?')),
                TextColumn::make('name')
                    ->weight('bold')
                    ->description(fn (User $record): ?string => $record->email)
                    ->searchable(),
                TextColumn::make('kind')
                    ->label('Type')
                    ->state(fn (User $record): string => $record->email ? 'Registered' : 'Guest')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Registered' ? 'success' : 'gray'),
                TextColumn::make('admin')
                    ->label('Admin')
                    ->state(fn (User $record): ?string => $record->isAllowlistedAdmin() ? 'env' : ($record->is_admin ? 'yes' : null))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'env' ? 'warning' : 'info')
                    ->placeholder('—'),
                TextColumn::make('game_results_count')
                    ->label('Games')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('wins_count')
                    ->label('Wins')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('game_results_max_played_at')
                    ->label('Last played')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('registered')
                    ->label('Registered')
                    ->placeholder('Everyone')
                    ->trueLabel('Registered only')
                    ->falseLabel('Guests only')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('email'),
                        false: fn (Builder $q) => $q->whereNull('email'),
                        blank: fn (Builder $q) => $q,
                    ),
                TernaryFilter::make('is_admin')
                    ->label('Admins'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('toggleAdmin')
                    ->label(fn (User $record): string => $record->is_admin ? 'Revoke admin' : 'Make admin')
                    ->icon(fn (User $record): string => $record->is_admin ? 'heroicon-o-shield-exclamation' : 'heroicon-o-shield-check')
                    ->color(fn (User $record): string => $record->is_admin ? 'danger' : 'success')
                    // Only registered, non-env-pinned users, and never yourself (avoid self-lockout).
                    ->visible(fn (User $record): bool => $record->email !== null && ! $record->isAllowlistedAdmin() && $record->id !== auth()->id())
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->forceFill(['is_admin' => ! $record->is_admin])->save()),
                Action::make('revokeTokens')
                    ->label('Force log out')
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Revoke every access token for this user, signing them out on all devices.')
                    ->action(fn (User $record) => $record->tokens()->delete()),
                DeleteAction::make()
                    ->before(fn (User $record) => $record->tokens()->delete()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(fn (Collection $records) => DB::table('personal_access_tokens')
                            ->where('tokenable_type', User::class)
                            ->whereIn('tokenable_id', $records->pluck('id'))
                            ->delete()),
                ]),
            ]);
    }
}
