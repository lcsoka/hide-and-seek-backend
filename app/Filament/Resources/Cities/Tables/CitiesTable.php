<?php

namespace App\Filament\Resources\Cities\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                ImageColumn::make('image')->label('Photo')->disk('public')->height(40)->defaultImageUrl(null),
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('key')->color('gray')->searchable(),
                TextColumn::make('default_size')->label('Size')->badge()->sortable(),
                TextColumn::make('available_modes')
                    ->label('Transit')
                    ->state(fn ($record) => is_array($record->available_modes) ? implode(', ', $record->available_modes) : '')
                    ->wrap()
                    ->color('gray'),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('sort')->alignCenter()->sortable(),
            ])
            ->filters([
                SelectFilter::make('is_active')->options([1 => 'Active', 0 => 'Inactive']),
                SelectFilter::make('default_size')->options(['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large']),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
