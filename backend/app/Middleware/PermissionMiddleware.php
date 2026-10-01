<?php

namespace App\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $permission
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $permission): Response
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

        // Check permission with tenant context
        if (!$user->hasPermission($permission, $organizationId, $projectId)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
