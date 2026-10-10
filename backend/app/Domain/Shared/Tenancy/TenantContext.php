<?php

namespace App\Domain\Shared\Tenancy;

use App\Domain\Authorization\DataScope;
use App\Domain\Authorization\PermissionResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;

/**
 * The authenticated tenant user and their organization. The organization is always
 * derived from the user account (one user = one company), never from request input.
 * Permissions and data scope are resolved lazily, inside the tenant context, so that
 * the RLS-protected role tables are readable.
 */
final class TenantContext
{
    /** @var array<string, true>|null */
    private ?array $permissions = null;

    private ?DataScope $scope = null;

    public function __construct(
        public readonly Organization $organization,
        public readonly User $user,
    ) {}

    public function organizationId(): string
    {
        return $this->organization->id;
    }

    public function userId(): string
    {
        return $this->user->id;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return array_keys($this->permissionMap());
    }

    public function can(string $permission): bool
    {
        return isset($this->permissionMap()[$permission]);
    }

    public function scope(): DataScope
    {
        return $this->scope ??= app(PermissionResolver::class)->dataScope($this->user);
    }

    /** Drop memoized permissions (after the user's own roles changed in this request). */
    public function refresh(): void
    {
        $this->permissions = null;
        $this->scope = null;
    }

    /** @return array<string, true> */
    private function permissionMap(): array
    {
        return $this->permissions ??= array_fill_keys(
            app(PermissionResolver::class)->tenantPermissions($this->user),
            true,
        );
    }
}
