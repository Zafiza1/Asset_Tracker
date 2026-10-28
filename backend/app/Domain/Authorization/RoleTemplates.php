<?php

namespace App\Domain\Authorization;

/**
 * Role templates copied into every new organization (docs/05-rbac-matrix.md §2).
 * After provisioning, roles are tenant data and can be edited, except the locked ADMIN.
 */
final class RoleTemplates
{
    public const ADMIN = 'ADMIN';

    public const SUPERVISOR = 'SUPERVISOR';

    public const SUPER_ADMIN = 'SUPER_ADMIN';

    /** @return array<string, array{name: string, description: string, locked: bool, permissions: list<string>}> */
    public static function tenant(): array
    {
        $allTenantPermissions = PermissionCatalog::codes(PermissionCatalog::SCOPE_TENANT);
        $supervisorPermissions = array_filter($allTenantPermissions, fn($perm) => $perm !== 'license.manage');
        $adminPermissions = array_filter($supervisorPermissions, fn($perm) =>
            !str_starts_with($perm, 'user.') &&
            !str_starts_with($perm, 'organization.')
        );

        return [
            self::SUPER_ADMIN => [
                'name' => 'Super Admin',
                'description' => 'Pemilik organisasi dengan akses penuh dan hak beli lisensi.',
                'locked' => true,
                'permissions' => $allTenantPermissions,
            ],
            self::SUPERVISOR => [
                'name' => 'Supervisor',
                'description' => 'Akses penuh atas organisasi (kecuali manajemen lisensi).',
                'locked' => false,
                'permissions' => array_values($supervisorPermissions),
            ],
            self::ADMIN => [
                'name' => 'Admin',
                'description' => 'Akses manajemen aset dan transaksi (kecuali user, organisasi, dan lisensi).',
                'locked' => false,
                'permissions' => array_values($adminPermissions),
            ],
        ];
    }

    /** @return array<string, array{name: string, description: string, permissions: list<string>}> */
    public static function platform(): array
    {
        return [
            self::SUPER_ADMIN => [
                'name' => 'Super Admin',
                'description' => 'Mengelola platform, organisasi, dan Generic Master.',
                'permissions' => PermissionCatalog::codes(PermissionCatalog::SCOPE_PLATFORM),
            ],
        ];
    }
}
