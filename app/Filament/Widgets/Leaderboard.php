<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Top players by wins, then total time survived — the carrot for registering. */
class Leaderboard extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Leaderboard — top hiders')
            ->query(
                User::query()
                    ->whereHas('gameResults')
                    ->withCount([
                        'gameResults',
                        'gameResults as wins_count' => fn (Builder $q) => $q->where('won', true),
                    ])
                    ->withSum('gameResults as total_hide_s', 'hide_time_s')
                    ->withMax('gameResults as best_hide_s', 'hide_time_s')
                    ->orderByDesc('wins_count')
                    ->orderByDesc('total_hide_s')
            )
            ->paginated([10, 25])
            ->recordUrl(fn (User $record): string => UserResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (User $r): ?string => $r->email ?? 'guest'),
                TextColumn::make('game_results_count')->label('Games')->badge()->color('gray'),
                TextColumn::make('wins_count')->label('Wins')->badge()->color('success'),
                TextColumn::make('total_hide_s')->label('Total hidden')->formatStateUsing(fn (?int $s): string => self::hms((int) $s)),
                TextColumn::make('best_hide_s')->label('Best')->formatStateUsing(fn (?int $s): string => self::hms((int) $s)),
            ]);
    }

    private static function hms(int $s): string
    {
        $h = intdiv($s, 3600);

        return $h > 0 ? sprintf('%dh %02dm', $h, intdiv($s % 3600, 60)) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
