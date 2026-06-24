<?php

namespace App\Filament\Resources\Questions\Tables;

use App\Enums\QuestionCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('category')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('prompt')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('reward')
                    ->label('Reward')
                    ->state(fn ($record) => "draw {$record->reward_draw} / keep {$record->reward_keep}")
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_custom')
                    ->label('Custom')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(QuestionCategory::class),
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
