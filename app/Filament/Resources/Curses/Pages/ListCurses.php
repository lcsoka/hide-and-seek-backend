<?php

namespace App\Filament\Resources\Curses\Pages;

use App\Filament\Resources\Curses\CurseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurses extends ListRecords
{
    protected static string $resource = CurseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
