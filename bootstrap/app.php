<?php

use App\Exceptions\QuestionTruthNotReady;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Channels + the broadcasting-auth route are registered in AppServiceProvider
        // so the auth route uses Sanctum (token) at /api/broadcasting/auth.
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [SetLocale::class]);
        // Keep the admin panel usable while the app is in maintenance mode (e.g. during a deploy),
        // so the ops page can still trigger + watch the deploy. The public API/site stay 503'd.
        // Livewire v3 serves its update route under a hashed prefix (`livewire-<hash>/update`), so
        // `livewire-*` is what actually matches it — `livewire/*` never did (the deploy-log poll 503'd).
        $middleware->preventRequestsDuringMaintenance(except: ['admin', 'admin/*', 'livewire/*', 'livewire-*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        // Expected transient retry signal when Overpass is momentarily down — the underlying
        // failures are already logged, so keep this out of the logs + Sentry.
        $exceptions->dontReport(QuestionTruthNotReady::class);
        // Report unhandled exceptions to Sentry (no-op unless SENTRY_LARAVEL_DSN is set).
        Integration::handles($exceptions);
    })->create();
