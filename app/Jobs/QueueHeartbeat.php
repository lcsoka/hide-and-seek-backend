<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched every minute by the scheduler. When a worker processes it, it stamps a heartbeat —
 * so a stale stamp means no worker is running (see SystemHealth::queue()).
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('health:queue', now()->timestamp, now()->addMinutes(10));
    }
}
