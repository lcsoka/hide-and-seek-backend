<?php

namespace App\Filament\Resources\Feedback\Tables;

use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeedbackTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('subject')
                    ->limit(40)
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('message')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('session.join_code')
                    ->label('Session')
                    ->placeholder('—'),
                TextColumn::make('contact')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(FeedbackType::class),
                SelectFilter::make('status')
                    ->options(FeedbackStatus::class),
            ])
            ->recordActions([
                EditAction::make()->label('Triage'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
