<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Concerns\PacksTranslations;
use App\Filament\Resources\Questions\QuestionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuestion extends CreateRecord
{
    use PacksTranslations;

    protected const TR = ['title', 'prompt'];

    protected static string $resource = QuestionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->packTranslations($data);
    }
}
