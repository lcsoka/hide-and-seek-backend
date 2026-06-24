<?php

use App\Http\Controllers\Api\FeedbackController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Public: submit a suggestion or bug report.
Route::post('/feedback', [FeedbackController::class, 'store']);
