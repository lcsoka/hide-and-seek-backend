<?php

namespace App\Filament\Resources\GameResults\Pages;

use App\Filament\Resources\GameResults\GameResultResource;
use Filament\Resources\Pages\ListRecords;

class ListGameResults extends ListRecords
{
    protected static string $resource = GameResultResource::class;
}
