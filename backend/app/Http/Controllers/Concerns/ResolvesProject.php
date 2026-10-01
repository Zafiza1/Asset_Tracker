<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * For project-owned resources: every action requires the X-Project-Id context
 * that TenantMiddleware resolves. Fetching the Project here also re-validates
 * it belongs to the current organization context (Project itself carries the
 * TenantScope global scope).
 */
trait ResolvesProject
{
    protected function resolveProject(): Project
    {
        $projectId = app(TenantContext::class)->projectId();

        if (!$projectId) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'A project context (X-Project-Id header) is required for this operation',
            ], 422));
        }

        return Project::findOrFail($projectId);
    }
}
