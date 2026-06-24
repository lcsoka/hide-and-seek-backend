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
use UnitEnum;

class CurseResource extends Resource
{
    protected static ?string $model = Curse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

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
