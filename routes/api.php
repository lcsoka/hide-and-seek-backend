<?php

use App\Http\Controllers\Api\ActionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DebugController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Middleware\EnsureDebugAccess;
use Illuminate\Support\Facades\Route;

// Public.
Route::post('/auth/guest', [AuthController::class, 'guest']);
Route::post('/feedback', [FeedbackController::class, 'store']);
Route::get('/questions', [CatalogController::class, 'questions']);
Route::get('/curses', [CatalogController::class, 'curses']);

// Authenticated (guest or, later, registered token).
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/sessions', [SessionController::class, 'store']);
    Route::post('/sessions/{code}/join', [SessionController::class, 'join']);
    Route::get('/sessions/{session}', [SessionController::class, 'show']);
    Route::get('/sessions/{session}/state', [SessionController::class, 'state']);
    Route::post('/sessions/{session}/start', [SessionController::class, 'start']);
    Route::post('/sessions/{session}/actions', [ActionController::class, 'store']);
    Route::post('/sessions/{session}/location', [LocationController::class, 'store'])
        ->middleware('throttle:120,1');
});

// Developer/debug API — gated by GAME_DEBUG + developer token; unreachable in production.
Route::middleware(EnsureDebugAccess::class)->prefix('sessions/{session}/debug')->group(function () {
    Route::get('/state', [DebugController::class, 'state']);
    Route::post('/act-as', [DebugController::class, 'actAs']);
    Route::post('/location', [DebugController::class, 'location']);
    Route::post('/seed-players', [DebugController::class, 'seedPlayers']);
    Route::post('/state', [DebugController::class, 'forceState']);
    Route::post('/timer/{key}/expire', [DebugController::class, 'expireTimer']);
});
