<?php

namespace App\Filament\Resources\ActionLogs\Pages;

use App\Filament\Resources\ActionLogs\ActionLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateActionLog extends CreateRecord
{
    protected static string $resource = ActionLogResource::class;
}
