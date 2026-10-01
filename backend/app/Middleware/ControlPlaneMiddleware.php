<?php

namespace App\Middleware;

use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For Control Plane routes (organizations, projects, memberships), which name
 * their tenant explicitly in the URL instead of the X-Organization-Id /
 * X-Project-Id context. Runs with an empty TenantContext so the TenantScope
 * global scope stays inert and a user's default context cannot hide the
 * addressed organization; access is decided by the policies, and listings
 * scope their queries explicitly.
 */
class ControlPlaneMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantScope::clearContext();

        return $next($request);
    }
}
