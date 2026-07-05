<?php

namespace App\Support;

use App\Jobs\SendWebPush;
use App\Models\Player;
use App\Models\Session;

/**
 * Decides who should receive a Web Push for a given game event and dispatches the (queued) send.
 * Recipient rules respect roles — e.g. only the hider is nudged when a question is asked — and the
 * actor is never notified about their own action.
 */
class PushNotifier
{
    /** True once VAPID keys are configured; otherwise every send is a no-op. */
    public static function configured(): bool
    {
        return config('webpush.vapid.public_key') !== '' && config('webpush.vapid.private_key') !== '';
    }

    /** Map a broadcast game event to a push, if it warrants one. */
    public function forGameEvent(Session $session, string $type, ?string $actorPlayerId): void
    {
        if (! self::configured()) {
            return;
        }

        [$audience, $key] = match ($type) {
            'QuestionAsked' => ['hider', 'push.question_asked'],
            'QuestionAnswered' => ['seekers', 'push.question_answered'],
            'CursePlayed' => ['seekers', 'push.curse_played'],
            'RoundStarted' => ['all', 'push.round_started'],
            'GameEnded' => ['all', 'push.game_ended'],
            default => [null, null],
        };
        if ($audience === null) {
            return;
        }

        $userIds = $this->recipients($session, $audience, $actorPlayerId);
        if (! empty($userIds)) {
            SendWebPush::dispatch($userIds, $key, [], '/s/'.$session->id, $type);
        }
    }

    /** Notify the host when someone joins their lobby. */
    public function forLobbyJoin(Session $session, Player $joiner): void
    {
        if (! self::configured()) {
            return;
        }

        $host = $session->players->firstWhere('is_host', true);
        if ($host === null || $host->user_id === null || $host->id === $joiner->id) {
            return;
        }

        SendWebPush::dispatch([$host->user_id], 'push.player_joined', ['name' => $joiner->display_name], '/s/'.$session->id, 'PlayerJoined');
    }

    /**
     * @return array<int, int> distinct user ids of the target players (registered users only)
     */
    private function recipients(Session $session, string $audience, ?string $actorPlayerId): array
    {
        return $session->players
            ->filter(fn (Player $p) => $p->user_id !== null && $p->id !== $actorPlayerId)
            ->filter(fn (Player $p) => match ($audience) {
                'hider' => $p->role === 'hider',
                'seekers' => $p->role === 'seeker',
                default => true,
            })
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }
}
