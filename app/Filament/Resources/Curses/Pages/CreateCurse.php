<?php

namespace App\Filament\Resources\Curses\Pages;

use App\Filament\Concerns\PacksTranslations;
use App\Filament\Resources\Curses\CurseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCurse extends CreateRecord
{
    use PacksTranslations;

    protected const TR = ['name', 'cost', 'description'];

    protected static string $resource = CurseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->packTranslations($data);
    }
}
