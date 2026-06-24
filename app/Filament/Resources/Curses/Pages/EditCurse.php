<?php

namespace App\Filament\Resources\Curses\Pages;

use App\Filament\Resources\Curses\CurseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCurse extends EditRecord
{
    protected static string $resource = CurseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
