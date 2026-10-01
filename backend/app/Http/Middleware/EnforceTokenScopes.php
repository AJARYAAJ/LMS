<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tokens issued to OAuth apps carry scopes: "read" for GET requests, "write"
 * for changes. Account, security and workspace settings stay off-limits to apps.
 */
class EnforceTokenScopes
{
    private const APP_FORBIDDEN = ['api/v1/settings/', 'api/v1/auth/', 'api/v1/oauth/', 'api/v1/connected-accounts', 'api/v1/push'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->can('*')) {
            return $next($request);
        }

        $path = $request->path();
        if ($path !== 'api/v1/auth/me' && collect(self::APP_FORBIDDEN)->contains(fn ($p) => str_starts_with($path.'/', $p))) {
            abort(403, 'Apps can’t access this part of the API.');
        }
        $needed = $request->isMethodSafe() ? 'read' : 'write';
        if (! $token->can($needed)) {
            abort(403, "This app wasn’t given the “{$needed}” permission.");
        }

        return $next($request);
    }
}
