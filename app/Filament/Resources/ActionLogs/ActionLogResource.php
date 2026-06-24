<?php

namespace App\Filament\Resources\ActionLogs;

use App\Filament\Resources\ActionLogs\Pages\ListActionLogs;
use App\Filament\Resources\ActionLogs\Schemas\ActionLogForm;
use App\Filament\Resources\ActionLogs\Tables\ActionLogsTable;
use App\Models\ActionLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ActionLogResource extends Resource
{
    protected static ?string $model = ActionLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Audit';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'type';

    /** Append-only audit trail: read-only in the admin. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

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
        ];
    }
}
