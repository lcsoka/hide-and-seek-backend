<?php

namespace App\Filament\Resources\GameResults;

use App\Filament\Resources\GameResults\Pages\ListGameResults;
use App\Filament\Resources\GameResults\Tables\GameResultsTable;
use App\Models\GameResult;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Durable per-game outcomes — read-only history feeding stats + leaderboards. */
class GameResultResource extends Resource
{
    protected static ?string $model = GameResult::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.users');
    }

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'game-results';

    public static function getModelLabel(): string
    {
        return __('resources.game_results.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('resources.game_results.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return GameResultsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGameResults::route('/'),
        ];
    }
}
