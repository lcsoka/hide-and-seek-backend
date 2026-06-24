<?php

namespace App\Filament\Resources\ActionLogs\Pages;

use App\Filament\Resources\ActionLogs\ActionLogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditActionLog extends EditRecord
{
    protected static string $resource = ActionLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
