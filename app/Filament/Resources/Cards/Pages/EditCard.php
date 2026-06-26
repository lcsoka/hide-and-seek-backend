<?php

namespace App\Filament\Resources\Cards\Pages;

use App\Filament\Concerns\PacksTranslations;
use App\Filament\Resources\Cards\CardResource;
use App\Models\Card;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCard extends EditRecord
{
    use PacksTranslations;

    protected const TR = ['name', 'cost', 'description'];

    protected static string $resource = CardResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillTranslations($data, $this->getRecord());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Card::normalizeFormData($this->packTranslations($data));
    }
}
