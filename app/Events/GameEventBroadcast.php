<?php

namespace App\Events;

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
