<?php

namespace App\Filament\Pages;

use App\Models\Card;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** A live overview of the hider deck composition (the "entire deck" at a glance). */
class Deck extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.deck';

    /** The official deck size (24 curses + 21 powerups + 25 time-bonuses). */
    public const OFFICIAL_TOTAL = 70;

    public static function getNavigationLabel(): string
    {
        return 'Deck';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.content');
    }

    public function getTitle(): string
    {
        return 'Hider deck';
    }

    /** Per-type rows + copies, the grand total, and the official-target delta. */
    public function composition(): array
    {
        $cards = Card::query()->where('is_active', true)->orderBy('sort')->get();
        $stat = fn (string $type) => [
            'rows' => $cards->where('type', $type)->count(),
            'copies' => (int) $cards->where('type', $type)->sum('count'),
        ];
        $total = (int) $cards->sum('count');

        return [
            'total' => $total,
            'official' => self::OFFICIAL_TOTAL,
            'delta' => $total - self::OFFICIAL_TOTAL,
            'types' => [
                'curse' => $stat('curse') + ['label' => 'Curses', 'color' => 'danger'],
                'powerup' => $stat('powerup') + ['label' => 'Powerups', 'color' => 'info'],
                'time_bonus' => $stat('time_bonus') + ['label' => 'Time bonuses', 'color' => 'success'],
            ],
        ];
    }

    /** Active cards grouped by type, for the breakdown lists. */
    public function cardsByType(): Collection
    {
        return Card::query()->where('is_active', true)->orderBy('sort')->get()->groupBy('type');
    }
}
