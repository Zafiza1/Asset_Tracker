<?php

namespace App\Domain\Authorization;

/**
 * Role templates copied into every new organization (docs/05-rbac-matrix.md §2).
 * After provisioning, roles are tenant data and can be edited, except the locked ORG_ADMIN.
 */
final class RoleTemplates
{
    public const ORG_ADMIN = 'ORG_ADMIN';

    public const PLATFORM_ADMIN = 'PLATFORM_ADMIN';

    /** @return array<string, array{name: string, description: string, locked: bool, permissions: list<string>}> */
    public static function tenant(): array
    {
        $view = ['organization.view', 'catalog.generic.view', 'master.tenant.view', 'asset.view', 'transaction.view', 'report.view'];

        return [
            self::ORG_ADMIN => [
                'name' => 'Administrator Organisasi',
                'description' => 'Akses penuh atas organisasi.',
                'locked' => true,
                'permissions' => PermissionCatalog::codes(PermissionCatalog::SCOPE_TENANT),
            ],
            'ASSET_MANAGER' => [
                'name' => 'Asset Manager',
                'description' => 'Mengelola master perusahaan, aset, dan transaksi.',
                'locked' => false,
                'permissions' => [...$view,
                    'user.view',
                    'master.tenant.create', 'master.tenant.update', 'master.tenant.archive', 'master.tenant.migrate',
                    'asset.create', 'asset.update', 'asset.assign', 'asset.transfer', 'asset.status_change', 'asset.dispose',
                    'asset.archive', 'asset.restore', 'document.view', 'document.manage',
                    'transaction.create', 'transaction.submit', 'transaction.approve', 'transaction.reject',
                    'transaction.post', 'transaction.reverse', 'transaction.correct',
                    'report.export', 'workflow.view',
                ],
            ],
            'OPERATOR' => [
                'name' => 'Operator',
                'description' => 'Mengajukan registrasi dan transaksi aset harian.',
                'locked' => false,
                'permissions' => [...$view,
                    'asset.create', 'asset.update', 'asset.assign', 'asset.transfer', 'asset.status_change',
                    'document.view', 'document.manage', 'transaction.create', 'transaction.submit',
                ],
            ],
            'APPROVER' => [
                'name' => 'Approver',
                'description' => 'Menyetujui atau menolak transaksi.',
                'locked' => false,
                'permissions' => [...$view, 'document.view', 'transaction.approve', 'transaction.reject', 'workflow.view'],
            ],
            'AUDITOR' => [
                'name' => 'Auditor',
                'description' => 'Akses baca untuk audit, laporan, dan ekspor.',
                'locked' => false,
                'permissions' => [...$view, 'user.view', 'role.view', 'document.view', 'report.export', 'audit.view', 'workflow.view'],
            ],
            'VIEWER' => [
                'name' => 'Viewer',
                'description' => 'Akses baca aset, transaksi, dan laporan.',
                'locked' => false,
                'permissions' => $view,
            ],
        ];
    }

    /** @return array<string, array{name: string, description: string, permissions: list<string>}> */
    public static function platform(): array
    {
        return [
            self::PLATFORM_ADMIN => [
                'name' => 'Platform Administrator',
                'description' => 'Mengelola platform, organisasi, dan Generic Master.',
                'permissions' => PermissionCatalog::codes(PermissionCatalog::SCOPE_PLATFORM),
            ],
        ];
    }
}
