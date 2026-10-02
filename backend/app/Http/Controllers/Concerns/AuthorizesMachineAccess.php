<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ApiKey;
use App\Models\Integration;
use App\Tenancy\TenantScope;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Authorization for routes behind MachineOrUserMiddleware: an API key must
 * carry the scope (and, if restricted, match the integration); a user must
 * hold the permission in the current organization/project.
 */
trait AuthorizesMachineAccess
{
    protected function authorizeMachineOrUser(Request $request, string $scope, string $userPermission, ?Integration $integration = null): void
    {
        $key = $request->attributes->get('api_key');

        if ($key instanceof ApiKey) {
            $allowed = $key->allows($scope) && (!$integration || $key->canUseIntegration($integration));
        } else {
            $user = $request->user();
            $allowed = $user && ($user->isPlatformAdmin() || $user->hasPermission(
                $userPermission,
                TenantScope::getCurrentOrganizationId(),
                TenantScope::getCurrentProjectId()
            ));
        }

        if (!$allowed) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'This action is unauthorized.',
            ], 403));
        }
    }

    /**
     * @return array{0: int, 1: int} organization and project from the context
     */
    protected function requireProjectContext(): array
    {
        $organizationId = TenantScope::getCurrentOrganizationId();
        $projectId = TenantScope::getCurrentProjectId();

        if (!$organizationId || !$projectId) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'A project context (X-Project-Id header) is required for this operation',
            ], 422));
        }

        return [$organizationId, $projectId];
    }
}
