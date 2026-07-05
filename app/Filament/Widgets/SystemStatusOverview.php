<?php

namespace App\Filament\Widgets;

use App\Support\SystemHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Live service health on the dashboard (DB, cache, Redis, Reverb, workers, scheduler) + version. */
class SystemStatusOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $health = app(SystemHealth::class);

        $stats = array_map(fn (array $s) => Stat::make($s['label'], $s['ok'] ? 'Up' : 'Down')
            ->description($s['detail'])
            ->descriptionIcon($s['ok'] ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle')
            ->color($s['ok'] ? 'success' : 'danger'), $health->services());

        $v = $health->version();
        $stats[] = Stat::make('Version', $v['current'] ?? '—')
            ->description($v['up_to_date'] ? 'up to date' : ($v['available'] ? 'update available → '.$v['remote'] : ($v['error'] ?? '—')))
            ->descriptionIcon($v['available'] ? 'heroicon-m-arrow-up-circle' : 'heroicon-m-check-badge')
            ->color($v['available'] ? 'warning' : ($v['up_to_date'] ? 'success' : 'gray'));

        return $stats;
    }
}
