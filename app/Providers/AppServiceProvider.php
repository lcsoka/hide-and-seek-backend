<?php

namespace App\Providers;

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
        //
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
    }
}
