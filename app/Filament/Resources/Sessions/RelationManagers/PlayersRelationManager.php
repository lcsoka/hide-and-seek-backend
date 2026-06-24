<?php

namespace App\Filament\Resources\Sessions\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlayersRelationManager extends RelationManager
{
    protected static string $relationship = 'players';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('display_name')
                    ->required()
                    ->maxLength(255),
                Select::make('team_id')
                    ->relationship('team', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('No team'),
                TextInput::make('role')
                    ->helperText('Mode-defined, e.g. hider / seeker.'),
                Toggle::make('is_host')
                    ->inline(false),
                TextInput::make('last_lat')
                    ->numeric()
                    ->label('Latitude'),
                TextInput::make('last_lng')
                    ->numeric()
                    ->label('Longitude'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('display_name')
            ->columns([
                TextColumn::make('display_name')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('role')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('team.name')
                    ->label('Team')
                    ->placeholder('—'),
                IconColumn::make('is_host')
                    ->label('Host')
                    ->boolean(),
                TextColumn::make('last_location_at')
                    ->label('Located')
                    ->since()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
