<?php

namespace App\Middleware;

use App\Models\Project;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a module's routes: `->middleware('module:maintenance')` only lets the
 * request through when that module is enabled for the current project
 * (Section 14). Must run after the `tenant` middleware.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $moduleSlug): Response
    {
        $projectId = app(TenantContext::class)->projectId();

        if (!$projectId) {
            return response()->json([
                'success' => false,
                'message' => 'A project context (X-Project-Id header) is required',
            ], 422);
        }

        $project = Project::find($projectId);

        if (!$project || !$project->hasModuleEnabled($moduleSlug)) {
            return response()->json([
                'success' => false,
                'message' => "Module [{$moduleSlug}] is not enabled for this project",
            ], 403);
        }

        return $next($request);
    }
}
