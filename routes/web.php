<?php

use Illuminate\Support\Facades\Route;

// This host serves the API (/api) + the admin panel; the root is just a small info payload
// so hitting the bare domain never shows Laravel's welcome splash. The admin URL is deliberately
// NOT advertised here. Liveness checks use the dedicated /up route (see bootstrap/app.php).
Route::get('/', fn () => response()->json([
    'name' => config('app.name').' API',
    'status' => 'ok',
    'game' => rtrim((string) config('app.web_url', ''), '/') ?: null,
]));
