<?php

namespace App\Filament\Resources\Cards\Pages;

use App\Filament\Concerns\PacksTranslations;
use App\Filament\Resources\Cards\CardResource;
use App\Models\Card;
use Filament\Resources\Pages\CreateRecord;

class CreateCard extends CreateRecord
{
    use PacksTranslations;

    protected const TR = ['name', 'cost', 'description'];

    protected static string $resource = CardResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Card::normalizeFormData($this->packTranslations($data));
    }
}
