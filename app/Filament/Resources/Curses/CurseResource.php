<?php

namespace App\Filament\Resources\Curses;

use App\Filament\Resources\Curses\Pages\CreateCurse;
use App\Filament\Resources\Curses\Pages\EditCurse;
use App\Filament\Resources\Curses\Pages\ListCurses;
use App\Filament\Resources\Curses\Schemas\CurseForm;
use App\Filament\Resources\Curses\Tables\CursesTable;
use App\Models\Curse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CurseResource extends Resource
{
    protected static ?string $model = Curse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.content');
    }

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'curses';

    public static function getModelLabel(): string
    {
        return __('resources.curses.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('resources.curses.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CurseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CursesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurses::route('/'),
            'create' => CreateCurse::route('/create'),
            'edit' => EditCurse::route('/{record}/edit'),
        ];
    }
}
