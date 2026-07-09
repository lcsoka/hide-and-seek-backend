<?php

use App\Http\Controllers\Api\ActionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\DebugController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Middleware\EnsureDebugAccess;
use Illuminate\Support\Facades\Route;

// Every REST endpoint is versioned under /api/v1.
Route::prefix('v1')->group(function () {
    // Public.
    Route::post('/auth/guest', [AuthController::class, 'guest']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::post('/feedback', [FeedbackController::class, 'store']);
    Route::get('/questions', [CatalogController::class, 'questions']);
    Route::get('/curses', [CatalogController::class, 'curses']);
    Route::get('/cities', [CatalogController::class, 'cities']);
    // Web Push: the VAPID public key the client needs to subscribe.
    Route::get('/push/public-key', [PushController::class, 'publicKey']);
    // Cached server-side Overpass proxy — the web app's only path to OSM data.
    Route::post('/geo/overpass', [GeoController::class, 'overpass'])->middleware('throttle:240,1');

    // Authenticated (guest or registered token).
    Route::middleware('auth:sanctum')->group(function () {
        // Optional accounts: register promotes the current guest in place; profile + avatar.
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/profile/stats', [AuthController::class, 'stats']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/profile/avatar', [AuthController::class, 'uploadAvatar'])->middleware('throttle:20,1');
        // GDPR: permanently delete the account + personal data (right to erasure).
        Route::delete('/profile', [AuthController::class, 'deleteAccount']);

        // Web Push subscriptions for this device.
        Route::post('/push/subscribe', [PushController::class, 'subscribe']);
        Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);

        // User-generated content (a registered user's own custom curses + questions).
        Route::get('/my/content', [ContentController::class, 'index']);
        Route::post('/my/curses', [ContentController::class, 'storeCurse']);
        Route::patch('/my/curses/{card}', [ContentController::class, 'updateCurse']);
        Route::delete('/my/curses/{card}', [ContentController::class, 'destroyCurse']);
        Route::post('/my/questions', [ContentController::class, 'storeQuestion']);
        Route::patch('/my/questions/{question}', [ContentController::class, 'updateQuestion']);
        Route::delete('/my/questions/{question}', [ContentController::class, 'destroyQuestion']);

        // The user's still-live games, so they can rejoin after leaving.
        Route::get('/my/sessions', [SessionController::class, 'mySessions']);
        Route::post('/sessions', [SessionController::class, 'store']);
        Route::post('/sessions/{code}/join', [SessionController::class, 'join']);
        Route::get('/sessions/{session}', [SessionController::class, 'show']);
        Route::get('/sessions/{session}/state', [SessionController::class, 'state']);
        // The askable questions for this game (official + the host's custom).
        Route::get('/sessions/{session}/questions', [SessionController::class, 'questions']);
        // Missed-event catch-up after a reconnect (?since=<last seq the client saw>).
        Route::get('/sessions/{session}/events', [SessionController::class, 'events']);
        Route::post('/sessions/{session}/start', [SessionController::class, 'start']);
        Route::post('/sessions/{session}/actions', [ActionController::class, 'store']);
        Route::post('/sessions/{session}/location', [LocationController::class, 'store'])
            ->middleware('throttle:120,1');
        Route::post('/sessions/{session}/media', [MediaController::class, 'store'])
            ->middleware('throttle:60,1');
    });

    // Developer/debug API — gated by GAME_DEBUG + developer token; unreachable in production.
    // Resolve a join code → god view (so the dev "spectate" can take a code or a session id).
    Route::middleware(EnsureDebugAccess::class)->get('debug/sessions/{code}', [DebugController::class, 'resolveCode']);
    Route::middleware(EnsureDebugAccess::class)->prefix('sessions/{session}/debug')->group(function () {
        Route::get('/state', [DebugController::class, 'state']);
        Route::post('/act-as', [DebugController::class, 'actAs']);
        Route::post('/location', [DebugController::class, 'location']);
        Route::post('/seed-players', [DebugController::class, 'seedPlayers']);
        Route::post('/token', [DebugController::class, 'mintToken']);
        Route::post('/state', [DebugController::class, 'forceState']);
        Route::post('/timer/{key}/expire', [DebugController::class, 'expireTimer']);
        Route::get('/cards', [DebugController::class, 'cards']);
        Route::post('/give-card', [DebugController::class, 'giveCard']);
        Route::post('/eval-question', [DebugController::class, 'evalQuestion']);
    });
});
