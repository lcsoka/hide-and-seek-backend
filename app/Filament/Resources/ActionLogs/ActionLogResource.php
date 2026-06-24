<?php

namespace App\Filament\Resources\ActionLogs;

use App\Filament\Resources\ActionLogs\Pages\CreateActionLog;
use App\Filament\Resources\ActionLogs\Pages\EditActionLog;
use App\Filament\Resources\ActionLogs\Pages\ListActionLogs;
use App\Filament\Resources\ActionLogs\Schemas\ActionLogForm;
use App\Filament\Resources\ActionLogs\Tables\ActionLogsTable;
use App\Models\ActionLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ActionLogResource extends Resource
{
    protected static ?string $model = ActionLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ActionLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActionLogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActionLogs::route('/'),
            'create' => CreateActionLog::route('/create'),
            'edit' => EditActionLog::route('/{record}/edit'),
        ];
    }
}
