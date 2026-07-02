<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Sessions\SessionResource;
use App\Models\Session;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The newest sessions, with a click-through to the resource. */
class RecentSessions extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent sessions')
            ->query(Session::query()->with('host')->latest())
            ->defaultPaginationPageOption(5)
            ->paginationPageOptions([5, 10, 25])
            ->recordUrl(fn (Session $record): string => SessionResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('join_code')->label('Code')->weight('bold')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('state')->badge()->color('gray'),
                TextColumn::make('players_count')->counts('players')->label('Players')->badge()->color('primary'),
                TextColumn::make('host.display_name')->label('Host')->placeholder('—'),
                TextColumn::make('created_at')->since()->label('Started')->sortable(),
            ]);
    }
}
