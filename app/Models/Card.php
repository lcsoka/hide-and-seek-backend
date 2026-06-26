<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * A hider-deck card: a curse, a powerup, or a time-bonus. `type` selects which
 * fields apply (`effect` for curses, `power` for powerups, `minutes` for time-bonuses);
 * `count` is how many copies go into the shuffled deck.
 */
class Card extends Model
{
    use HasTranslations, HasUuids;

    /** Translatable (per-locale) attributes — stored as JSON by spatie. */
    public array $translatable = ['name', 'cost', 'description'];

    protected $fillable = [
        'type',
        'key',
        'name',
        'cost',
        'description',
        'effect',
        'power',
        'minutes',
        'count',
        'is_custom',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'effect' => 'array',
        'minutes' => 'integer',
        'count' => 'integer',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Normalize admin-form data: clear fields irrelevant to the card's type and prune the
     * curse `effect` so empty form sub-groups don't persist as meaningless config.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeFormData(array $data): array
    {
        $type = $data['type'] ?? 'curse';
        $data['power'] = $type === 'powerup' ? ($data['power'] ?? null) : null;
        $data['minutes'] = $type === 'time_bonus' ? ($data['minutes'] ?? null) : null;
        $data['effect'] = $type === 'curse' ? self::cleanEffect($data['effect'] ?? null) : null;

        return $data;
    }

    /** Drop empty/falsey consequence flags so `effect` only holds real mechanics (or null). */
    public static function cleanEffect(?array $effect): ?array
    {
        $effect ??= [];
        $out = [];
        if (! empty($effect['requires_proof'])) {
            $out['requires_proof'] = true;
        }
        if (! empty($effect['blocks_asking'])) {
            $out['blocks_asking'] = true;
        }
        if (! empty($effect['duration_s'])) {
            $out['duration_s'] = (int) $effect['duration_s'];
        }
        $dice = $effect['dice'] ?? [];
        if (! empty($dice['count']) && ! empty($dice['sides'])) {
            $out['dice'] = ['count' => (int) $dice['count'], 'sides' => (int) $dice['sides']];
            if (($dice['target'] ?? null) !== null && $dice['target'] !== '') {
                $out['dice']['target'] = (int) $dice['target'];
            }
        }
        $dc = $effect['disable_categories'] ?? [];
        if (! empty($dc['count'])) {
            $out['disable_categories'] = ['count' => (int) $dc['count'], 'mode' => $dc['mode'] ?? 'random', 'rotates' => ! empty($dc['rotates'])];
        }
        if (! empty($effect['bonus_draws']['count'])) {
            $out['bonus_draws'] = ['count' => (int) $effect['bonus_draws']['count']];
        }

        return $out ?: null;
    }
}
