<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates server-to-server / web-form lead capture via X-Api-Key.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Api-Key') ?? $request->query('api_key');

        $apiKey = $key ? ApiKey::withoutGlobalScopes()->where('key_hash', hash('sha256', $key))->first() : null;

        abort_unless($apiKey, 401, 'Invalid API key.');

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        Tenant::set($apiKey->organization_id);

        try {
            return $next($request);
        } finally {
            Tenant::set(null);
        }
    }
}
