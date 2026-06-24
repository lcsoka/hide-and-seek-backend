<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

// Public.
Route::post('/auth/guest', [AuthController::class, 'guest']);
Route::post('/feedback', [FeedbackController::class, 'store']);

// Authenticated (guest or, later, registered token).
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/sessions', [SessionController::class, 'store']);
    Route::post('/sessions/{code}/join', [SessionController::class, 'join']);
    Route::get('/sessions/{session}', [SessionController::class, 'show']);
    Route::get('/sessions/{session}/state', [SessionController::class, 'state']);
});
