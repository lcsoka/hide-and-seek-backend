<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the developer/debug API: enabled only when GAME_DEBUG is on AND the caller
 * presents the developer token. Off → 404 (unreachable in production); wrong token → 403.
 */
class EnsureDebugAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(filter_var(config('game.debug.enabled'), FILTER_VALIDATE_BOOLEAN), 404);

        $expected = config('game.debug.token');
        abort_unless(filled($expected) && hash_equals((string) $expected, (string) $request->header('X-Developer-Token')), 403);

        return $next($request);
    }
}
