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
        return [
            self::ADMIN => [
                'name' => 'Admin',
                'description' => 'Akses penuh atas organisasi.',
                'locked' => true,
                'permissions' => PermissionCatalog::codes(PermissionCatalog::SCOPE_TENANT),
            ],
            self::SUPERVISOR => [
                'name' => 'Supervisor',
                'description' => 'Akses baca aset, transaksi, dan laporan.',
                'locked' => false,
                'permissions' => ['organization.view', 'catalog.generic.view', 'master.tenant.view', 'asset.view', 'transaction.view', 'report.view'],
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
