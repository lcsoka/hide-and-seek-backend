<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Illuminate\Support\Collection;

/**
 * Publish/unpublish actions shared by the Cards and Questions tables — the moderation levers for
 * player-made (is_custom) content, which is created live and can be pulled by an admin.
 */
trait ModeratesContent
{
    public static function toggleActive(): Action
    {
        return Action::make('toggleActive')
            ->label(fn ($record): string => $record->is_active ? 'Deactivate' : 'Activate')
            ->icon(fn ($record): string => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->color(fn ($record): string => $record->is_active ? 'gray' : 'success')
            ->action(fn ($record) => $record->forceFill(['is_active' => ! $record->is_active])->save());
    }

    /** @return array<int, BulkAction> */
    public static function bulkActivation(): array
    {
        return [
            BulkAction::make('activate')
                ->label('Activate')
                ->icon('heroicon-o-eye')
                ->color('success')
                ->action(fn (Collection $records) => $records->each->forceFill(['is_active' => true])->each->save()),
            BulkAction::make('deactivate')
                ->label('Deactivate')
                ->icon('heroicon-o-eye-slash')
                ->color('gray')
                ->action(fn (Collection $records) => $records->each->forceFill(['is_active' => false])->each->save()),
        ];
    }
}
