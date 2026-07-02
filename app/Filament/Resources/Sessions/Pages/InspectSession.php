<?php

namespace App\Filament\Resources\Sessions\Pages;

use App\Filament\Resources\Sessions\SessionResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * A friendly, editable view of a session's mode-owned state + config — a typed collapsible tree
 * instead of a raw JSON blob. This page IS its own Livewire component, so wire:model binds
 * straight into the nested arrays and Save persists them.
 */
class InspectSession extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SessionResource::class;

    protected string $view = 'filament.resources.sessions.pages.inspect-session';

    /** @var array<string, mixed> */
    public array $config = [];

    /** @var array<string, mixed> */
    public array $stateData = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->config = $this->record->config ?? [];
        $this->stateData = $this->record->state_data ?? [];
    }

    public function getTitle(): string
    {
        return 'State — '.$this->record->join_code;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon('heroicon-o-check')
                ->keyBindings(['mod+s'])
                ->action('save'),
            Action::make('edit')
                ->label('Raw editor')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->url(fn (): string => SessionResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function save(): void
    {
        $this->record->update([
            'config' => $this->config ?: null,
            'state_data' => $this->stateData ?: null,
        ]);

        Notification::make()->title('State saved')->success()->send();
    }

    /** A human-readable snapshot of the key state, for the summary header. */
    public function summary(): array
    {
        $s = $this->stateData;
        $hiderId = $s['hider_id'] ?? ($s['last_round']['hider_id'] ?? null);
        $hider = $hiderId ? $this->record->players->firstWhere('id', $hiderId)?->display_name : null;

        return [
            'phase' => $this->record->state,
            'status' => $this->record->status->value,
            'round' => $s['round'] ?? null,
            'hider' => $hider,
            'players' => $this->record->players->count(),
            'questions' => is_array($s['questions'] ?? null) ? count($s['questions']) : 0,
            'curses' => is_array($s['curses_played'] ?? null) ? count($s['curses_played']) : 0,
            'timers' => array_filter([
                'Hiding started' => $this->ts($s['hiding_started_at'] ?? null),
                'Hiding deadline' => $this->ts($s['hiding_deadline'] ?? null),
                'Seeking started' => $this->ts($s['seeking_started_at'] ?? null),
                'Question deadline' => $this->ts($s['question_deadline'] ?? null),
            ]),
        ];
    }

    private function ts(mixed $unix): ?string
    {
        return is_numeric($unix) ? Carbon::createFromTimestamp((int) $unix)->format('H:i:s') : null;
    }
}
