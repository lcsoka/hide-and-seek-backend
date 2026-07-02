<?php

namespace App\Filament\Widgets;

use App\Models\GameResult;
use App\Models\Session;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/** Account + outcome numbers: who's registering, and how games are playing out. */
class UserStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $weekAgo = Carbon::now()->subDays(7);
        $registered = User::query()->whereNotNull('email')->count();
        $newRegistered = User::query()->whereNotNull('email')->where('created_at', '>=', $weekAgo)->count();
        $guests = User::query()->whereNull('email')->count();

        $avgHide = (int) round((float) GameResult::query()->avg('hide_time_s'));
        $topCity = Session::query()
            ->get(['config']) // via models so the JSON cast applies (query-builder pluck returns raw JSON)
            ->pluck('config')
            ->map(function ($c) {
                // config['city'] is usually a {key,name,lat,lng} object; tolerate a bare slug too.
                $city = is_array($c) ? ($c['city'] ?? null) : null;

                return is_array($city) ? ($city['name'] ?? $city['key'] ?? null) : (is_string($city) ? $city : null);
            })
            ->filter()
            ->countBy()
            ->sortDesc();

        return [
            Stat::make('Registered users', $registered)
                ->description('+'.$newRegistered.' this week')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('success'),
            Stat::make('Guests', $guests)
                ->description('throwaway identities')
                ->descriptionIcon('heroicon-m-user'),
            Stat::make('Avg hiding time', sprintf('%d:%02d', intdiv($avgHide, 60), $avgHide % 60))
                ->description('per recorded game')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),
            Stat::make('Top city', $topCity->keys()->first() ? ucfirst((string) $topCity->keys()->first()) : '—')
                ->description(($topCity->first() ?? 0).' games')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('primary'),
        ];
    }
}
