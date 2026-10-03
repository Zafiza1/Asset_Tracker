<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Authenticate user and return token
     */
    public function login(string $email, string $password, ?string $deviceName = null): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            $this->auditService->logLoginFailed($email, request()->ip());
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->status !== 'active') {
            throw new AuthenticationException('Account is not active');
        }

        $token = $user->createToken($deviceName ?? 'api-token');

        $this->auditService->logLoginSuccess($user->id);

        return [
            'user' => $user->load(['organizations', 'projects']),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Register new user
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'status' => 'active',
        ]);

        // Assign default role if specified
        if (isset($data['role_slug'])) {
            $user->assignRole($data['role_slug']);
        }

        $token = $user->createToken('registration-token');

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Logout user (revoke current token)
     */
    public function logout(User $user): void
    {
        $this->auditService->logLogout($user->id);
        $user->currentAccessToken()->delete();
    }

    /**
     * Logout user from all devices
     */
    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Get current authenticated user with tenant context
     */
    public function getCurrentUser(User $user, ?int $organizationId = null, ?int $projectId = null): array
    {
        $user->load(['organizations', 'projects', 'roles', 'permissions']);

        $data = [
            'user' => $user,
            'organizations' => $user->organizations,
            'projects' => $user->projects,
        ];

        if ($organizationId) {
            $data['current_organization'] = $user->organizations()->where('organizations.id', $organizationId)->first();
            $data['organization_roles'] = $user->getRolesForContext($organizationId);
            $data['organization_permissions'] = $user->getPermissionsForContext($organizationId);
        }

        if ($projectId) {
            $data['current_project'] = $user->projects()->where('projects.id', $projectId)->first();
            $data['project_roles'] = $user->getRolesForContext($organizationId, $projectId);
            $data['project_permissions'] = $user->getPermissionsForContext($organizationId, $projectId);
            // Enabled business modules, so clients can show module features.
            $data['project_modules'] = $user->canAccessProject($projectId)
                ? \App\Models\ProjectModule::withoutGlobalScopes()
                    ->where('project_modules.project_id', $projectId)
                    ->where('project_modules.status', 'enabled')
                    ->join('modules', 'modules.id', '=', 'project_modules.module_id')
                    ->pluck('modules.slug')
                    ->all()
                : [];
        }

        return $data;
    }

    /**
     * Refresh token
     */
    public function refreshToken(User $user, ?string $deviceName = null): array
    {
        // Revoke current token
        $user->currentAccessToken()->delete();

        // Create new token
        $token = $user->createToken($deviceName ?? 'api-token');

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Verify user access to organization
     */
    public function verifyOrganizationAccess(User $user, int $organizationId): bool
    {
        return $user->canAccessOrganization($organizationId);
    }

    /**
     * Verify user access to project
     */
    public function verifyProjectAccess(User $user, int $projectId): bool
    {
        return $user->canAccessProject($projectId);
    }

    /**
     * Switch organization context
     */
    public function switchOrganization(User $user, int $organizationId): array
    {
        if (!$this->verifyOrganizationAccess($user, $organizationId)) {
            throw new AuthenticationException('Access denied to this organization');
        }

        // Persist as the user's default context (used by TenantMiddleware on
        // requests that don't pass an explicit X-Organization-Id header).
        // forceFill: these columns are system-managed, not mass-assignable.
        $user->forceFill([
            'default_organization_id' => $organizationId,
            'default_project_id' => null,
        ])->save();

        \App\Tenancy\TenantScope::setOrganizationId($organizationId);
        \App\Tenancy\TenantScope::setProjectId(null);

        return $this->getCurrentUser($user, $organizationId);
    }

    /**
     * Switch project context
     */
    public function switchProject(User $user, int $projectId): array
    {
        if (!$this->verifyProjectAccess($user, $projectId)) {
            throw new AuthenticationException('Access denied to this project');
        }

        // Not via $user->projects(): access may come from an organization-level
        // role rather than project membership.
        $project = Project::withoutGlobalScope(TenantScope::class)->findOrFail($projectId);

        $user->forceFill([
            'default_organization_id' => $project->organization_id,
            'default_project_id' => $projectId,
        ])->save();

        \App\Tenancy\TenantScope::setOrganizationId($project->organization_id);
        \App\Tenancy\TenantScope::setProjectId($projectId);

        return $this->getCurrentUser($user, $project->organization_id, $projectId);
    }
}
