<?php

namespace App\Filament\Resources\Sessions\Pages;

use App\Filament\Resources\Sessions\SessionResource;
use App\Game\ReplayBuilder;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class ReplaySession extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SessionResource::class;

    protected string $view = 'filament.resources.sessions.pages.replay-session';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Replay — '.$this->record->join_code;
    }

    /** The full replay payload (tracks, events, questions, curses, zone) for the Alpine player. */
    public function replayBundle(): array
    {
        return app(ReplayBuilder::class)->build($this->record);
    }
}
