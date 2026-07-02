<?php

namespace App\Providers;

use App\Game\Geo\MapDataSource;
use App\Game\Geo\OverpassMapDataSource;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Live OSM backend for the geo evaluators (swap to PostGIS later, or a fake in tests).
        $this->app->bind(MapDataSource::class, OverpassMapDataSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The API returns resources directly (no "data" envelope), matching openapi.yaml.
        JsonResource::withoutWrapping();

        // Token-authenticated channel auth at POST /api/broadcasting/auth.
        Broadcast::routes(['middleware' => ['auth:sanctum'], 'prefix' => 'api']);
        require base_path('routes/channels.php');

        // Password-reset links point at the SPA's reset page (not a backend web route).
        ResetPassword::createUrlUsing(fn ($user, string $token): string => rtrim((string) config('app.web_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()));
    }
}
