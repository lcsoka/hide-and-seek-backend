<?php

namespace App\Models;

use App\Enums\GameSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A playable city. Admin-managed: the cover image, the play size tied to it, and the
 * transit modes that actually exist there (so a smaller city can't offer "metró").
 */
class City extends Model
{
    protected $fillable = [
        'key',
        'name',
        'lat',
        'lng',
        'image',
        'default_size',
        'available_modes',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'default_size' => GameSize::class,
        'available_modes' => 'array',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /** Public URL of the uploaded cover photo, or null if none uploaded yet. */
    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }
}
