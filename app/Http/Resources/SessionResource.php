<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'join_code' => $this->join_code,
            'game_mode' => $this->game_mode?->value,
            'state' => $this->state,
            'status' => $this->status?->value,
            'host_player_id' => $this->host_player_id,
            'config' => $this->config,
            'players' => PlayerResource::collection($this->whenLoaded('players')),
            'teams' => $this->whenLoaded('teams', fn () => $this->teams->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'color' => $team->color,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
