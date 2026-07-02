<?php

namespace App\Events;

use App\Models\GameEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts one game event. `everyone`-scoped events go on the session presence
 * channel; `player`-scoped events go on that player's private channel, so the
 * payload never reaches anyone else. Event name maps 1:1 to the AsyncAPI names.
 */
class GameEventBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Event types too high-volume / ephemeral to persist for catch-up. Positions re-sync
     * via /state on reconnect, so replaying stale PlayerMoved events would be wasteful.
     */
    private const EPHEMERAL = ['PlayerMoved'];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{scope: string, player_id?: string}  $visibility
     */
    public function __construct(
        public string $sessionId,
        public string $type,
        public array $payload = [],
        public array $visibility = ['scope' => 'everyone'],
    ) {}

    /**
     * Persist the event (so a reconnecting client can replay it) AND broadcast it live.
     * The persisted row's auto-increment id is threaded into the live payload as `_seq`
     * so the client can track its catch-up cursor. Ephemeral events are broadcast only.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{scope: string, player_id?: string}  $visibility
     */
    public static function record(string $sessionId, string $type, array $payload = [], array $visibility = ['scope' => 'everyone']): void
    {
        if (! in_array($type, self::EPHEMERAL, true)) {
            $event = GameEvent::create([
                'session_id' => $sessionId,
                'type' => $type,
                'payload' => $payload ?: null,
                'visibility_scope' => $visibility['scope'] ?? 'everyone',
                'visibility_player_id' => $visibility['player_id'] ?? null,
            ]);
            $payload['_seq'] = $event->id;
        }

        self::dispatch($sessionId, $type, $payload, $visibility);
    }

    /**
     * @return array<int, PresenceChannel|PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if (($this->visibility['scope'] ?? 'everyone') === 'player' && isset($this->visibility['player_id'])) {
            return [new PrivateChannel("session.{$this->sessionId}.player.{$this->visibility['player_id']}")];
        }

        return [new PresenceChannel("session.{$this->sessionId}")];
    }

    public function broadcastAs(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
