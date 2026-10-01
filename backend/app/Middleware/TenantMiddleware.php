<?php

namespace App\Middleware;

use App\Models\Project;
use App\Models\Scopes\TenantScope as TenantGlobalScope;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Never let a previous request's context (long-running workers,
        // tests) leak into this one.
        TenantScope::clearContext();

        // Resolve tenant context: explicit header/query wins, falling back to
        // the user's persisted default (set via /auth/switch-organization|project)
        $explicitOrganizationId = $request->header('X-Organization-Id') ?? $request->query('organization_id');
        $explicitProjectId = $request->header('X-Project-Id') ?? $request->query('project_id');

        $organizationId = $explicitOrganizationId ?? $user->default_organization_id;
        $projectId = $explicitProjectId ?? $user->default_project_id;

        // Validate organization access if provided
        if ($organizationId) {
            if (!$user->canAccessOrganization((int)$organizationId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this organization',
                ], 403);
            }
        }

        // Validate project access if provided
        if ($projectId) {
            $projectOrganizationId = Project::withoutGlobalScope(TenantGlobalScope::class)
                ->whereKey((int)$projectId)
                ->value('organization_id');

            // The project must belong to the organization in context:
            // permission checks combine the two (organization-level roles
            // apply to that organization's projects only).
            if ($organizationId && $projectOrganizationId !== null && $projectOrganizationId !== (int)$organizationId) {
                if ($explicitProjectId === null) {
                    // A stale default project from another organization —
                    // drop it rather than fail the request.
                    $projectId = null;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Project does not belong to this organization',
                    ], 403);
                }
            }
        }

        if ($projectId) {
            if (!$user->canAccessProject((int)$projectId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied to this project',
                ], 403);
            }

            $organizationId ??= $projectOrganizationId;
            TenantScope::setProjectId((int)$projectId);
        }

        if ($organizationId) {
            TenantScope::setOrganizationId((int)$organizationId);
        }

        return $next($request);
    }
}
