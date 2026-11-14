<?php

namespace App\Domain\Approvals;

/**
 * Changes that need approval when made by a user without approval.manage: every write of the
 * Asset Management and Master Data modules. Transactions apply at once; `transaction.create`
 * stays listed only so requests submitted before that change can still be shown and decided. The route declares its action with
 * the `approval:<action>` middleware; this class is the single list of actions and labels.
 */
final class ApprovalActions
{
    public const PERMISSION = 'approval.manage';

    public const CATEGORY_TRANSACTION = 'transaction';

    public const CATEGORY_ASSET = 'asset';

    public const CATEGORY_MASTER = 'master';

    public const CATEGORIES = [
        self::CATEGORY_TRANSACTION => 'Transaksi',
        self::CATEGORY_ASSET => 'Aset',
        self::CATEGORY_MASTER => 'Master Data',
    ];

    /** @var array<string, array{0: string, 1: string}> action => [category, label] */
    private const ACTIONS = [
        'transaction.create' => [self::CATEGORY_TRANSACTION, 'Pencatatan transaksi aset'],
        'asset.create' => [self::CATEGORY_ASSET, 'Penambahan aset'],
        'asset.update' => [self::CATEGORY_ASSET, 'Perubahan data aset'],
        'asset.bulk_update' => [self::CATEGORY_ASSET, 'Multiple Edit aset'],
        'asset.image' => [self::CATEGORY_ASSET, 'Perubahan gambar aset'],
        'asset.document' => [self::CATEGORY_ASSET, 'Perubahan dokumen aset'],
        'asset.archive' => [self::CATEGORY_ASSET, 'Penonaktifan aset'],
        'asset.restore' => [self::CATEGORY_ASSET, 'Pengaktifan kembali aset'],
        'asset.import' => [self::CATEGORY_ASSET, 'Impor aset dari Excel'],
        'asset.import_edits' => [self::CATEGORY_ASSET, 'Multiple Edit aset dari Excel'],
        'department.create' => [self::CATEGORY_MASTER, 'Penambahan departemen'],
        'department.update' => [self::CATEGORY_MASTER, 'Perubahan departemen'],
        'department.archive' => [self::CATEGORY_MASTER, 'Pengarsipan departemen'],
        'department.restore' => [self::CATEGORY_MASTER, 'Pemulihan departemen'],
        'location.create' => [self::CATEGORY_MASTER, 'Penambahan lokasi'],
        'location.update' => [self::CATEGORY_MASTER, 'Perubahan lokasi'],
        'location.archive' => [self::CATEGORY_MASTER, 'Pengarsipan lokasi'],
        'location.restore' => [self::CATEGORY_MASTER, 'Pemulihan lokasi'],
        'location.import' => [self::CATEGORY_MASTER, 'Impor lokasi dari Excel'],
        'location.import_edits' => [self::CATEGORY_MASTER, 'Multiple Edit lokasi dari Excel'],
        'category.create' => [self::CATEGORY_MASTER, 'Penambahan kategori aset'],
        'category.update' => [self::CATEGORY_MASTER, 'Perubahan kategori aset'],
        'category.archive' => [self::CATEGORY_MASTER, 'Pengarsipan kategori aset'],
        'category.restore' => [self::CATEGORY_MASTER, 'Pemulihan kategori aset'],
        'category.delete' => [self::CATEGORY_MASTER, 'Penghapusan kategori aset'],
        'category.import' => [self::CATEGORY_MASTER, 'Impor kategori aset dari Excel'],
        'category.import_edits' => [self::CATEGORY_MASTER, 'Multiple Edit kategori aset dari Excel'],
        'category.attributes' => [self::CATEGORY_MASTER, 'Perubahan atribut kategori aset'],
    ];

    /**
     * Actions that are not tried out at submission: storing a file writes and deletes files,
     * which a database rollback cannot undo. They are only validated when approved.
     */
    private const NOT_TRIED = ['asset.image', 'asset.document'];

    public static function exists(string $action): bool
    {
        return isset(self::ACTIONS[$action]);
    }

    public static function category(string $action): string
    {
        return self::ACTIONS[$action][0];
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action][1] ?? $action;
    }

    public static function triedAtSubmission(string $action): bool
    {
        return ! in_array($action, self::NOT_TRIED, true);
    }

    /** Actions whose label contains $term (case-insensitive), for searching requests by type. @return list<string> */
    public static function matching(string $term): array
    {
        $term = mb_strtolower(trim($term));

        return $term === '' ? [] : array_keys(array_filter(self::ACTIONS, fn ($a) => str_contains(mb_strtolower($a[1]), $term)));
    }

    /**
     * What approving the request does to the data, for the approver before deciding.
     *
     * @return array{text: string, destructive: bool}
     */
    public static function impact(string $action): array
    {
        [$entity, $verb] = array_pad(explode('.', $action, 2), 2, '');
        $asset = $entity === 'asset';

        $text = match ($verb) {
            'create' => $action === 'transaction.create'
                ? 'Transaksi dicatat dan status atau lokasi aset yang terlibat diperbarui.'
                : ($asset ? 'Aset baru ditambahkan ke daftar aset.' : 'Data baru ditambahkan dan dapat langsung dipilih di form lain.'),
            'update' => 'Nilai pada data yang ada diganti dengan nilai yang diajukan (lihat kolom "Saat ini" dan "Diajukan").',
            'bulk_update' => 'Semua aset yang dipilih diubah sekaligus dengan nilai yang diajukan.',
            'image' => 'Gambar aset diganti atau dihapus.',
            'document' => 'Dokumen aset ditambahkan atau dihapus.',
            'archive' => $asset
                ? 'Aset dinonaktifkan: tidak lagi dihitung sebagai aset aktif dan tidak dapat ditransaksikan.'
                : 'Data diarsipkan dan tidak dapat dipilih lagi untuk data baru. Data lama yang memakainya tidak berubah.',
            'restore' => $asset ? 'Aset diaktifkan kembali dan dapat ditransaksikan lagi.' : 'Data dipulihkan dan dapat dipilih lagi.',
            'delete' => 'Data dihapus permanen dan tidak dapat dikembalikan.',
            'import' => 'Setiap baris file Excel ditambahkan sebagai data baru.',
            'import_edits' => 'Data yang ada diubah sesuai isi file Excel.',
            'attributes' => 'Atribut kategori berubah untuk kategori ini, subkategorinya, dan form aset di dalamnya.',
            default => 'Perubahan diterapkan persis seperti yang diajukan.',
        };

        return ['text' => $text, 'destructive' => in_array($verb, ['archive', 'delete', 'import_edits', 'bulk_update'], true)];
    }

    /** The master entity of a master data action (department, location, category). */
    public static function entity(string $action): string
    {
        return explode('.', $action)[0];
    }
}
