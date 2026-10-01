<?php

namespace App\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $role
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Tenant context as resolved and validated by TenantMiddleware, which
        // must run first; raw headers are never trusted here.
        $context = app(TenantContext::class);
        $organizationId = $context->organizationId();
        $projectId = $context->projectId();

        // Check role with tenant context
        if (!$user->hasRole($role, $organizationId, $projectId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have the required role',
                'required_role' => $role,
            ], 403);
        }

        return $next($request);
    }
}
