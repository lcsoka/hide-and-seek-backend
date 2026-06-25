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

    public function __construct(public string $sessionId, public int $seq) {}

    public function handle(HideAndSeekMode $mode): void
    {
        $session = Session::find($this->sessionId);
        if ($session === null) {
            return;
        }

        $mode->computePendingTruth($session, $this->seq);
    }
}
