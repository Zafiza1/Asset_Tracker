<?php

namespace App\Domain\Authorization;

use App\Domain\Authorization\Models\Permission;
use Illuminate\Support\Str;

/**
 * Single source of truth for permission codes (docs/05-rbac-matrix.md).
 * Synced into the `permissions` table by PermissionSeeder; codes are never renamed in place.
 */
final class PermissionCatalog
{
    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_TENANT = 'tenant';

    /** @var array<string, array{0: string, 1: string, 2: string}> code => [scope, group, description] */
    private const PERMISSIONS = [
        // Platform
        'platform.organization.view' => ['platform', 'platform', 'Melihat daftar organisasi'],
        'platform.organization.manage' => ['platform', 'platform', 'Membuat, mengubah, menangguhkan organisasi'],
        'platform.user.view' => ['platform', 'platform', 'Melihat pengguna platform'],
        'platform.user.manage' => ['platform', 'platform', 'Mengelola pengguna platform'],
        'platform.audit.view' => ['platform', 'platform', 'Melihat audit trail platform'],
        'platform.support.access' => ['platform', 'platform', 'Membuka sesi akses dukungan (read-only)'],
        'master.generic.view' => ['platform', 'generic_master', 'Melihat Generic Master (platform)'],
        'master.generic.manage' => ['platform', 'generic_master', 'Mengelola draft Generic Master'],
        'master.generic.release' => ['platform', 'generic_master', 'Merilis dan men-deprecate versi Generic Master'],

        // Tenant
        'organization.view' => ['tenant', 'organization', 'Melihat profil organisasi'],
        'organization.manage' => ['tenant', 'organization', 'Mengubah profil organisasi'],
        'user.view' => ['tenant', 'user', 'Melihat pengguna'],
        'user.create' => ['tenant', 'user', 'Membuat pengguna'],
        'user.update' => ['tenant', 'user', 'Mengubah pengguna dan data scope'],
        'user.deactivate' => ['tenant', 'user', 'Menangguhkan/menonaktifkan pengguna'],
        'role.view' => ['tenant', 'role', 'Melihat role'],
        'role.manage' => ['tenant', 'role', 'Mengelola role dan penugasan role'],
        'catalog.generic.view' => ['tenant', 'master', 'Melihat katalog Generic Master'],
        'master.tenant.view' => ['tenant', 'master', 'Melihat master perusahaan'],
        'master.tenant.create' => ['tenant', 'master', 'Membuat master perusahaan'],
        'master.tenant.update' => ['tenant', 'master', 'Mengubah dan merilis master perusahaan'],
        'master.tenant.archive' => ['tenant', 'master', 'Mengarsipkan master perusahaan'],
        'master.tenant.migrate' => ['tenant', 'master', 'Memigrasikan aset ke versi definisi baru'],
        'asset.view' => ['tenant', 'asset', 'Melihat aset'],
        'asset.create' => ['tenant', 'asset', 'Mengajukan registrasi aset'],
        'asset.update' => ['tenant', 'asset', 'Mengubah data deskriptif aset'],
        'asset.assign' => ['tenant', 'asset', 'Mengajukan perubahan penanggung jawab/peminjaman'],
        'asset.transfer' => ['tenant', 'asset', 'Mengajukan perpindahan lokasi'],
        'asset.status_change' => ['tenant', 'asset', 'Mengajukan perubahan status/kondisi'],
        'asset.dispose' => ['tenant', 'asset', 'Mengajukan pelepasan aset'],
        'asset.archive' => ['tenant', 'asset', 'Mengarsipkan aset'],
        'asset.restore' => ['tenant', 'asset', 'Memulihkan aset arsip'],
        'document.view' => ['tenant', 'document', 'Melihat dan mengunduh dokumen'],
        'document.manage' => ['tenant', 'document', 'Mengunggah dan mengarsipkan dokumen'],
        'transaction.view' => ['tenant', 'transaction', 'Melihat transaksi'],
        'transaction.create' => ['tenant', 'transaction', 'Membuat draft transaksi'],
        'transaction.submit' => ['tenant', 'transaction', 'Mengajukan transaksi'],
        'transaction.approve' => ['tenant', 'transaction', 'Menyetujui transaksi'],
        'transaction.reject' => ['tenant', 'transaction', 'Menolak/mengembalikan transaksi'],
        'transaction.post' => ['tenant', 'transaction', 'Memposting transaksi'],
        'transaction.reverse' => ['tenant', 'transaction', 'Membalik transaksi yang sudah diposting'],
        'transaction.correct' => ['tenant', 'transaction', 'Mengajukan transaksi koreksi'],
        'report.view' => ['tenant', 'report', 'Melihat dashboard dan laporan'],
        'report.export' => ['tenant', 'report', 'Mengekspor laporan'],
        'audit.view' => ['tenant', 'audit', 'Melihat audit trail organisasi'],
        'workflow.view' => ['tenant', 'workflow', 'Melihat konfigurasi workflow'],
        'workflow.manage' => ['tenant', 'workflow', 'Mengelola workflow approval'],
        'settings.manage' => ['tenant', 'settings', 'Mengelola pengaturan organisasi'],
    ];

    /** @return list<string> */
    public static function codes(?string $scope = null): array
    {
        return array_keys(array_filter(
            self::PERMISSIONS,
            fn (array $p) => $scope === null || $p[0] === $scope,
        ));
    }

    public static function exists(string $code, ?string $scope = null): bool
    {
        return isset(self::PERMISSIONS[$code]) && ($scope === null || self::PERMISSIONS[$code][0] === $scope);
    }

    /** Idempotently upsert the catalog into the permissions table. */
    public static function sync(): void
    {
        $now = now();
        $existing = Permission::query()->pluck('id', 'code');
        $rows = [];
        foreach (self::PERMISSIONS as $code => [$scope, $group, $description]) {
            $rows[] = [
                'id' => $existing[$code] ?? (string) Str::uuid7(),
                'code' => $code,
                'scope' => $scope,
                'group' => $group,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Permission::query()->upsert($rows, ['code'], ['scope', 'group', 'description', 'updated_at']);
    }
}
