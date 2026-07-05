<?php

use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Clean up abandoned / expired games.
Schedule::command('game:prune-abandoned')->everyFifteenMinutes();

// Liveness heartbeats read by the admin System page: the scheduler stamps its own, and dispatches a
// job that stamps the queue's when a worker processes it. A stale stamp => that process is down.
Schedule::call(fn () => Cache::put('health:scheduler', now()->timestamp, now()->addMinutes(10)))
    ->everyMinute()
    ->name('scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyMinute()->name('queue-heartbeat');
