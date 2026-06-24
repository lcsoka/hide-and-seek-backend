<?php

namespace App\Filament\Resources\Sessions\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActionLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'actionLogs';

    public function form(Schema $schema): Schema
    {
        // Read-only: backs the View modal only.
        return $schema
            ->components([
                TextInput::make('type')->disabled(),
                TextInput::make('created_at')->disabled(),
                Textarea::make('payload')
                    ->rows(10)
                    ->columnSpanFull()
                    ->disabled()
                    ->formatStateUsing(fn ($state) => filled($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : null),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('player.display_name')
                    ->label('Player')
                    ->placeholder('system'),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
