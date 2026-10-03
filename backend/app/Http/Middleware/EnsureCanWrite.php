<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only (viewer) users may browse but never mutate data.
 */
class EnsureCanWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $request->user()?->canWrite()) {
            abort(403, 'Your role is read-only.');
        }

        return $next($request);
    }
}
