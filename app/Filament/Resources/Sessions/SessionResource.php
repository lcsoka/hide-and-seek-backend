<?php

namespace App\Filament\Resources\Sessions;

use App\Filament\Resources\Sessions\Pages\CreateSession;
use App\Filament\Resources\Sessions\Pages\EditSession;
use App\Filament\Resources\Sessions\Pages\ListSessions;
use App\Filament\Resources\Sessions\RelationManagers\ActionLogsRelationManager;
use App\Filament\Resources\Sessions\RelationManagers\PlayersRelationManager;
use App\Filament\Resources\Sessions\RelationManagers\TeamsRelationManager;
use App\Filament\Resources\Sessions\Schemas\SessionForm;
use App\Filament\Resources\Sessions\Tables\SessionsTable;
use App\Models\Session;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SessionResource extends Resource
{
    protected static ?string $model = Session::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Game';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'join_code';

    public static function form(Schema $schema): Schema
    {
        return SessionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SessionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PlayersRelationManager::class,
            TeamsRelationManager::class,
            ActionLogsRelationManager::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSessions::route('/'),
            'create' => CreateSession::route('/create'),
            'edit' => EditSession::route('/{record}/edit'),
        ];
    }
}
