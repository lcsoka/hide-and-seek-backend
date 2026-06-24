<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Roster only — live coordinates are exposed (visibility-filtered) via /state.
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'role' => $this->role,
            'is_host' => $this->is_host,
            'team_id' => $this->team_id,
        ];
    }
}
