<?php

namespace App\Console\Commands;

use App\Enums\SessionStatus;
use App\Events\GameEventBroadcast;
use App\Models\Session;
use Illuminate\Console\Command;

class PruneAbandonedSessions extends Command
{
    protected $signature = 'game:prune-abandoned';

    protected $description = 'Mark idle sessions abandoned and delete old finished/abandoned sessions.';

    public function handle(): int
    {
        $lobbyIdle = (int) config('game.abandon.lobby_idle_minutes', 120);
        $activeIdle = (int) config('game.abandon.active_idle_minutes', 360);
        $retentionDays = (int) config('game.abandon.retention_days', 30);
        $now = now();

        // 1) Mark non-terminal sessions abandoned once they've been idle too long.
        $abandoned = 0;
        $live = Session::whereIn('status', [SessionStatus::Open, SessionStatus::Running])->get();
        foreach ($live as $session) {
            $idleSince = $session->last_activity_at ?? $session->created_at;
            $threshold = $session->state === 'lobby' ? $lobbyIdle : $activeIdle;

            if ($idleSince->lt($now->copy()->subMinutes($threshold))) {
                $session->update(['status' => SessionStatus::Abandoned, 'ended_at' => $now]);
                $session->actionLogs()->create([
                    'type' => 'abandoned',
                    'payload' => ['idle_since' => $idleSince->toISOString()],
                ]);
                GameEventBroadcast::dispatch($session->id, 'GameAbandoned', ['session_id' => $session->id]);
                $abandoned++;
            }
        }

        // 2) Delete old terminal sessions (retention / privacy). FK cascades children.
        $deleted = Session::whereIn('status', [SessionStatus::Finished, SessionStatus::Abandoned])
            ->where('ended_at', '<', $now->copy()->subDays($retentionDays))
            ->delete();

        $this->info("Abandoned {$abandoned} idle session(s); deleted {$deleted} expired session(s).");

        return self::SUCCESS;
    }
}
