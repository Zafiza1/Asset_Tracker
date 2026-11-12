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

    /** Inviting users; reserved for Super Admin, who also holds the license codes an invitation needs. */
    public const INVITE_PERMISSION = 'user.create';

    /** Editing the organization profile; Super Admin only, every other role just reads it. */
    public const ORGANIZATION_MANAGE_PERMISSION = 'organization.manage';

    /** Reading the organization profile; every default role holds it. */
    public const ORGANIZATION_VIEW_PERMISSION = 'organization.view';

    /** @return array<string, array{name: string, description: string, locked: bool, permissions: list<string>}> */
    public static function tenant(): array
    {
        $allTenantPermissions = PermissionCatalog::codes(PermissionCatalog::SCOPE_TENANT);
        // Supervisor: every group except license, no inviting and no profile editing.
        // Admin: the operational groups, plus reading the organization profile.
        $supervisorPermissions = array_diff($allTenantPermissions, PermissionCatalog::codesInGroups(['license']), [self::INVITE_PERMISSION, self::ORGANIZATION_MANAGE_PERMISSION]);
        $adminPermissions = [...PermissionCatalog::codesInGroups(['asset', 'document', 'master', 'report', 'transaction']), self::ORGANIZATION_VIEW_PERMISSION];

        return [
            self::SUPER_ADMIN => [
                'name' => 'Super Admin',
                'description' => 'Pemilik organisasi dengan akses penuh dan hak beli lisensi.',
                'locked' => true,
                'permissions' => $allTenantPermissions,
            ],
            self::SUPERVISOR => [
                'name' => 'Supervisor',
                'description' => 'Akses penuh atas organisasi (kecuali mengundang pengguna dan manajemen lisensi).',
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

    /**
     * Approval (approving or rejecting submitted changes) belongs to Super Admin and Supervisor
     * only: no other role, default or custom, may hold the approval permissions.
     */
    public static function mayHoldApproval(?string $templateCode): bool
    {
        return in_array($templateCode, [self::SUPER_ADMIN, self::SUPERVISOR], true);
    }

    /** Only Super Admin invites users: no other role, default or custom, may hold the permission. */
    public static function mayInvite(?string $templateCode): bool
    {
        return $templateCode === self::SUPER_ADMIN;
    }

    /** Only Super Admin edits the organization profile: no other role, default or custom, may hold it. */
    public static function mayManageOrganization(?string $templateCode): bool
    {
        return $templateCode === self::SUPER_ADMIN;
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
