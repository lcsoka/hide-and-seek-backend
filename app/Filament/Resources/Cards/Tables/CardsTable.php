<?php

namespace App\Filament\Resources\Cards\Tables;

use App\Filament\Concerns\ModeratesContent;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CardsTable
{
    use ModeratesContent;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->colors([
                        'danger' => 'curse',
                        'info' => 'powerup',
                        'success' => 'time_bonus',
                    ])
                    ->sortable(),
                TextColumn::make('name')->searchable()->weight('bold'),
                TextColumn::make('detail')
                    ->label('Mechanic')
                    ->state(fn ($record) => self::summary($record))
                    ->wrap(),
                TextColumn::make('author.name')
                    ->label('Author')
                    ->placeholder('official')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('count')->label('×')->alignCenter()->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    'curse' => 'Curse',
                    'powerup' => 'Powerup',
                    'time_bonus' => 'Time bonus',
                ]),
                SelectFilter::make('is_active')->options([1 => 'Active', 0 => 'Inactive']),
                SelectFilter::make('is_custom')->label('Source')->options([1 => 'Player-made', 0 => 'Official']),
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

    /** A one-line human summary of the card's mechanic. */
    private static function summary($record): string
    {
        if ($record->type === 'powerup') {
            return (string) $record->power;
        }
        if ($record->type === 'time_bonus') {
            $m = $record->minutes ?? [];

            return is_array($m) ? "+{$m['small']}/{$m['medium']}/{$m['large']} min (S/M/L)" : "+{$m} min";
        }

        $e = $record->effect ?? [];
        $parts = [];
        if ($e['requires_proof'] ?? false) {
            $parts[] = 'photo';
        }
        if ($e['blocks_asking'] ?? false) {
            $parts[] = 'blocks asking';
        }
        if ($e['dice'] ?? null) {
            $parts[] = 'dice';
        }
        if ($e['duration_s'] ?? null) {
            $parts[] = round($e['duration_s'] / 60).'m timer';
        }
        if ($d = $e['disable_categories'] ?? null) {
            $parts[] = "disable {$d['count']} cat";
        }
        if ($e['bonus_draws'] ?? null) {
            $parts[] = '+draws';
        }

        return $parts ? implode(' · ', $parts) : 'social';
    }
}
