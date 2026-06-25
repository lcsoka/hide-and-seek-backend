<?php

namespace App\Filament\Widgets;

use App\Enums\FeedbackStatus;
use App\Enums\SessionStatus;
use App\Models\ActionLog;
use App\Models\Feedback;
use App\Models\Player;
use App\Models\Session;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/** Headline numbers for the admin dashboard. */
class GameStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = Carbon::today();
        $spark = collect(range(6, 0))
            ->map(fn (int $d) => Session::query()->whereDate('created_at', $today->copy()->subDays($d))->count())
            ->all();

        $live = Session::query()->where('status', SessionStatus::Running)->count();
        $lobby = Session::query()->where('status', SessionStatus::Open)->count();
        $openFeedback = Feedback::query()->where('status', FeedbackStatus::Open)->count();

        return [
            Stat::make('Live games', $live)
                ->description($lobby.' waiting in lobby')
                ->descriptionIcon('heroicon-m-play')
                ->color('success'),
            Stat::make('Total sessions', Session::query()->count())
                ->description(Session::query()->whereDate('created_at', $today)->count().' today')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($spark)
                ->color('primary'),
            Stat::make('Players', Player::query()->count())
                ->description('across '.Session::query()->count().' sessions')
                ->descriptionIcon('heroicon-m-users'),
            Stat::make('Questions asked', ActionLog::query()->where('type', 'ask_question')->count())
                ->description('curses played: '.ActionLog::query()->where('type', 'play_curse')->count())
                ->descriptionIcon('heroicon-m-question-mark-circle')
                ->color('info'),
            Stat::make('Finished games', Session::query()->where('status', SessionStatus::Finished)->count())
                ->description(Session::query()->where('status', SessionStatus::Abandoned)->count().' abandoned')
                ->descriptionIcon('heroicon-m-flag')
                ->color('gray'),
            Stat::make('Open feedback', $openFeedback)
                ->description($openFeedback > 0 ? 'needs triage' : 'all clear')
                ->descriptionIcon('heroicon-m-inbox')
                ->color($openFeedback > 0 ? 'danger' : 'gray'),
        ];
    }
}
