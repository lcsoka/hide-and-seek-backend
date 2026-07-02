<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\AuthoredCardsRelationManager;
use App\Filament\Resources\Users\RelationManagers\AuthoredQuestionsRelationManager;
use App\Filament\Resources\Users\RelationManagers\GameResultsRelationManager;
use App\Filament\Resources\Users\RelationManagers\PlayersRelationManager;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.users');
    }

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'users';

    public static function getModelLabel(): string
    {
        return __('resources.users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('resources.users.plural');
    }

    /** Accounts are created by playing/registering, not from the admin. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::query()->whereNotNull('email')->count();
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            GameResultsRelationManager::class,
            PlayersRelationManager::class,
            AuthoredCardsRelationManager::class,
            AuthoredQuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
