<?php

namespace App\Jobs;

use App\Game\GameEngine;
use App\Models\Session;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FireGameTimer implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $guard  The state_data value for $key captured when scheduled;
     *                           if it no longer matches, the timer is stale and ignored.
     */
    public function __construct(
        public string $sessionId,
        public string $key,
        public ?int $guard = null,
    ) {}

    public function handle(GameEngine $engine): void
    {
        $session = Session::find($this->sessionId);

        if ($session !== null) {
            $engine->fireTimer($session, $this->key, $this->guard);
        }
    }
}
