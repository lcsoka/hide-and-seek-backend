<?php

namespace App\Filament\Resources\Curses\Pages;

use App\Filament\Concerns\PacksTranslations;
use App\Filament\Resources\Curses\CurseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCurse extends EditRecord
{
    use PacksTranslations;

    protected const TR = ['name', 'cost', 'description'];

    protected static string $resource = CurseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillTranslations($data, $this->getRecord());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->packTranslations($data);
    }
}
