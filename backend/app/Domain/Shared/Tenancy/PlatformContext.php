<?php

namespace App\Domain\Shared\Tenancy;

use App\Domain\Authorization\PermissionResolver;
use App\Domain\Identity\Models\User;

final class PlatformContext
{
    /** @var array<string, true>|null */
    private ?array $permissions = null;

    public function __construct(public readonly User $user) {}

    /** @return list<string> */
    public function permissions(): array
    {
        return array_keys($this->permissionMap());
    }

    public function can(string $permission): bool
    {
        return isset($this->permissionMap()[$permission]);
    }

    /** @return array<string, true> */
    private function permissionMap(): array
    {
        return $this->permissions ??= array_fill_keys(
            app(PermissionResolver::class)->platformPermissions($this->user),
            true,
        );
    }
}
