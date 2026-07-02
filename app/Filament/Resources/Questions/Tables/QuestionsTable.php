<?php

namespace App\Filament\Resources\Questions\Tables;

use App\Enums\QuestionCategory;
use App\Filament\Concerns\ModeratesContent;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuestionsTable
{
    use ModeratesContent;

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
                TextColumn::make('author.name')
                    ->label('Author')
                    ->placeholder('official')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'warning' : 'gray'),
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
                    ->label('Source')
                    ->options([1 => 'Player-made', 0 => 'Official']),
            ])
            ->recordActions([
                self::toggleActive(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ...self::bulkActivation(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
