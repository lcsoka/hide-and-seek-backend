<?php

namespace App\Filament\Resources\Curses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('cost')
                    ->placeholder('—')
                    ->color('gray'),
                TextColumn::make('description')
                    ->label('Effect')
                    ->limit(70)
                    ->wrap(),
                IconColumn::make('is_custom')
                    ->label('Custom')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->options([1 => 'Active', 0 => 'Inactive']),
                SelectFilter::make('is_custom')
                    ->options([1 => 'Custom', 0 => 'Official']),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
