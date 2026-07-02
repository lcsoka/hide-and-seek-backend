<?php

namespace App\Console\Commands;

use App\Enums\SessionStatus;
use App\Events\GameEventBroadcast;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAbandonedSessions extends Command
{
    protected $signature = 'game:prune-abandoned
        {--idle= : Override the idle threshold in minutes when marking sessions abandoned (e.g. 0 = abandon every idle session now)}
        {--purge : Delete terminal sessions and orphan guests immediately, ignoring the retention windows}';

    protected $description = 'Mark idle sessions abandoned, delete old finished/abandoned sessions, and prune orphan guest users/tokens.';

    public function handle(): int
    {
        $idleOverride = $this->option('idle');
        $lobbyIdle = $idleOverride !== null ? (int) $idleOverride : (int) config('game.abandon.lobby_idle_minutes', 120);
        $activeIdle = $idleOverride !== null ? (int) $idleOverride : (int) config('game.abandon.active_idle_minutes', 360);
        $retentionDays = (int) config('game.abandon.retention_days', 30);
        $guestRetentionDays = (int) config('game.abandon.guest_retention_days', 7);
        $purge = (bool) $this->option('purge');
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
                GameEventBroadcast::record($session->id, 'GameAbandoned', ['session_id' => $session->id]);
                $abandoned++;
            }
        }

        // 2) Delete terminal sessions (retention / privacy). FK cascades players/teams/action_logs.
        $deleted = Session::whereIn('status', [SessionStatus::Finished, SessionStatus::Abandoned])
            ->when(! $purge, fn ($q) => $q->where('ended_at', '<', $now->copy()->subDays($retentionDays)))
            ->delete();

        // 3) Prune guest cruft: guest users (no email) not tied to any live session, plus their
        //    Sanctum tokens (polymorphic — no FK cascade), and any expired tokens. Registered
        //    users (email set) and guests currently in an open/running game are never touched.
        $liveUserIds = Player::query()
            ->whereNotNull('user_id')
            ->whereHas('session', fn ($q) => $q->whereIn('status', [SessionStatus::Open, SessionStatus::Running]))
            ->distinct()->pluck('user_id');

        $guestIds = User::query()
            ->whereNull('email')
            ->whereNotIn('id', $liveUserIds)
            ->when(! $purge, fn ($q) => $q->where('created_at', '<', $now->copy()->subDays($guestRetentionDays)))
            ->pluck('id');

        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $guestIds)
            ->delete();
        $prunedGuests = User::whereIn('id', $guestIds)->delete();

        $expiredTokens = DB::table('personal_access_tokens')
            ->whereNotNull('expires_at')->where('expires_at', '<', $now)->delete();

        $this->info("Abandoned {$abandoned} idle session(s); deleted {$deleted} terminal session(s); pruned {$prunedGuests} guest user(s) and {$expiredTokens} expired token(s).");

        return self::SUCCESS;
    }
}
