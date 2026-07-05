<?php

namespace App\Filament\Pages;

use App\Support\SystemHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

/** Ops page: live service health, the deployed vs latest version, and a guarded deploy button + log. */
class SystemStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.system-status';

    public static function getNavigationLabel(): string
    {
        return 'System';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    public function getTitle(): string
    {
        return 'System status';
    }

    private function health(): SystemHealth
    {
        return app(SystemHealth::class);
    }

    /** @return array<int, array{key: string, label: string, ok: bool, detail: string}> */
    public function services(): array
    {
        return $this->health()->services();
    }

    /** @return array{current: ?string, remote: ?string, up_to_date: bool, available: bool, error: ?string} */
    public function version(): array
    {
        return $this->health()->version();
    }

    public function isDeploying(): bool
    {
        return $this->health()->isDeploying();
    }

    public function deployEnabled(): bool
    {
        return $this->health()->deployEnabled();
    }

    public function deployLog(): string
    {
        return $this->health()->deployLog();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshVersion')
                ->label('Check for updates')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->health()->refreshVersion()),
            Action::make('prune')
                ->label('Run cleanup')
                ->icon(Heroicon::OutlinedTrash)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Run session cleanup now?')
                ->modalDescription('Marks idle games abandoned (notifying any players still connected), deletes old finished/abandoned games, and prunes orphan guest accounts — the same job the scheduler runs every 15 minutes.')
                ->modalSubmitActionLabel('Run cleanup')
                ->action(function () {
                    Artisan::call('game:prune-abandoned');
                    Notification::make()->title('Cleanup complete')->body(trim(Artisan::output()))->success()->send();
                }),
            Action::make('deploy')
                ->label('Deploy latest')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Deploy the latest version?')
                ->modalDescription('Pulls main, rebuilds admin assets, migrates, and restarts the workers + Reverb. The public site shows a maintenance screen for ~1 minute; this admin panel stays up.')
                ->modalSubmitActionLabel('Deploy')
                // Always shown so it's discoverable, but disabled (with a reason) until the server is
                // wired for it: ADMIN_DEPLOY_ENABLED=true + deploy.sh present, and no deploy running.
                ->disabled(fn () => ! $this->health()->deployEnabled() || $this->health()->isDeploying())
                ->tooltip(fn (): ?string => match (true) {
                    $this->health()->isDeploying() => 'A deploy is already running.',
                    ! $this->health()->deployEnabled() => 'Disabled — set ADMIN_DEPLOY_ENABLED=true on the server to enable one-click deploys.',
                    default => null,
                })
                ->action(function () {
                    $this->health()->deploy();
                    Notification::make()->title('Deploy started')->body('Watch the log below — it refreshes automatically.')->success()->send();
                }),
        ];
    }
}
