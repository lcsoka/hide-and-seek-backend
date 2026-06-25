<?php

namespace App\Filament\Widgets;

use App\Models\Session;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/** Sessions created per day over the last fortnight. */
class SessionsChart extends ChartWidget
{
    protected ?string $heading = 'Sessions created (last 14 days)';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn (int $d) => Carbon::today()->subDays($d));

        return [
            'datasets' => [[
                'label' => 'Sessions',
                'data' => $days->map(fn (Carbon $day) => Session::query()->whereDate('created_at', $day)->count())->all(),
                'borderColor' => '#e11d48',
                'backgroundColor' => 'rgba(225, 29, 72, 0.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $days->map(fn (Carbon $day) => $day->format('M j'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
