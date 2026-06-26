<?php

namespace App\Filament\Resources\Cards;

use App\Filament\Resources\Cards\Pages\CreateCard;
use App\Filament\Resources\Cards\Pages\EditCard;
use App\Filament\Resources\Cards\Pages\ListCards;
use App\Filament\Resources\Cards\Schemas\CardForm;
use App\Filament\Resources\Cards\Tables\CardsTable;
use App\Models\Card;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CardResource extends Resource
{
    protected static ?string $model = Card::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Cards';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'cards';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.content');
    }

    public static function getModelLabel(): string
    {
        return 'Card';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Cards';
    }

    public static function form(Schema $schema): Schema
    {
        return CardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCards::route('/'),
            'create' => CreateCard::route('/create'),
            'edit' => EditCard::route('/{record}/edit'),
        ];
    }
}
