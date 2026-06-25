<?php

namespace App\Jobs;

use App\Game\Modes\HideAndSeek\HideAndSeekMode;
use App\Models\Session;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pre-computes the authoritative answer for the pending question off the request
 * path — matching/measuring/tentacles hit Overpass, which is slow. The result is
 * stored back on the pending question so resolving it later is instant. Guarded by
 * `seq` so a superseded question is ignored.
 */
class ComputeQuestionTruth implements ShouldQueue
{
    use Queueable;

    /** Retry transient Overpass failures with backoff before giving up. */
    public int $tries = 4;

    public function __construct(public string $sessionId, public int $seq) {}

    /** @return array<int, int> seconds between retries */
    public function backoff(): array
    {
        return [3, 8, 20];
    }

    public function handle(HideAndSeekMode $mode): void
    {
        $session = Session::find($this->sessionId);
        if ($session === null) {
            return;
        }

        $mode->computePendingTruth($session, $this->seq);
    }
}
