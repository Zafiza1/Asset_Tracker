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
        // Supervisor: every group except license. Admin: only the operational groups.
        $supervisorPermissions = array_diff($allTenantPermissions, PermissionCatalog::codesInGroups(['license']));
        $adminPermissions = PermissionCatalog::codesInGroups(['asset', 'document', 'master', 'report', 'transaction']);

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
                'description' => 'Akses aset, dokumen, master data, laporan, dan transaksi.',
                'locked' => false,
                'permissions' => array_values($adminPermissions),
            ],
        ];
    }

    /**
     * The permissions a default (template-based) role may hold: tenants can drop and re-add
     * these, but never grant more than the template does. Null for custom roles.
     *
     * @return list<string>|null
     */
    public static function ceiling(?string $templateCode): ?array
    {
        return $templateCode === null ? null : (self::tenant()[$templateCode]['permissions'] ?? null);
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
