<?php

namespace App\Http\Middleware;

use App\Security\FieldPermissions;
use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active === false) {
            abort(403, 'Your account is inactive.');
        }

        Tenant::set($user->organization_id);
        FieldPermissions::setViewer($user);

        try {
            return $next($request);
        } finally {
            Tenant::set(null);
            FieldPermissions::setViewer(null);
            FieldPermissions::flush();
        }
    }
}
