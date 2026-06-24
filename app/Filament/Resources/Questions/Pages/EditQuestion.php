<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Concerns\PacksTranslations;
use App\Filament\Resources\Questions\QuestionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditQuestion extends EditRecord
{
    use PacksTranslations;

    protected const TR = ['title', 'prompt'];

    protected static string $resource = QuestionResource::class;

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
