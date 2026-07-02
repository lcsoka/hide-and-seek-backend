<?php

namespace App\Filament\Resources\Sessions\Pages;

use App\Filament\Resources\Sessions\SessionResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSession extends EditRecord
{
    protected static string $resource = SessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('state')
                ->label('Visual state editor')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('info')
                ->url(fn (): string => SessionResource::getUrl('state', ['record' => $this->record])),
            DeleteAction::make(),
        ];
    }
}
