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
        'asset.bulk_update' => [self::CATEGORY_ASSET, 'Perubahan data aset (massal)'],
        'asset.image' => [self::CATEGORY_ASSET, 'Perubahan gambar aset'],
        'asset.document' => [self::CATEGORY_ASSET, 'Perubahan dokumen aset'],
        'asset.archive' => [self::CATEGORY_ASSET, 'Penonaktifan aset'],
        'asset.restore' => [self::CATEGORY_ASSET, 'Pengaktifan kembali aset'],
        'asset.import' => [self::CATEGORY_ASSET, 'Impor aset dari Excel'],
        'asset.import_edits' => [self::CATEGORY_ASSET, 'Edit massal aset dari Excel'],
        'department.create' => [self::CATEGORY_MASTER, 'Penambahan departemen'],
        'department.update' => [self::CATEGORY_MASTER, 'Perubahan departemen'],
        'department.archive' => [self::CATEGORY_MASTER, 'Pengarsipan departemen'],
        'department.restore' => [self::CATEGORY_MASTER, 'Pemulihan departemen'],
        'location.create' => [self::CATEGORY_MASTER, 'Penambahan lokasi'],
        'location.update' => [self::CATEGORY_MASTER, 'Perubahan lokasi'],
        'location.archive' => [self::CATEGORY_MASTER, 'Pengarsipan lokasi'],
        'location.restore' => [self::CATEGORY_MASTER, 'Pemulihan lokasi'],
        'location.import' => [self::CATEGORY_MASTER, 'Impor lokasi dari Excel'],
        'location.import_edits' => [self::CATEGORY_MASTER, 'Edit massal lokasi dari Excel'],
        'category.create' => [self::CATEGORY_MASTER, 'Penambahan kategori aset'],
        'category.update' => [self::CATEGORY_MASTER, 'Perubahan kategori aset'],
        'category.archive' => [self::CATEGORY_MASTER, 'Pengarsipan kategori aset'],
        'category.restore' => [self::CATEGORY_MASTER, 'Pemulihan kategori aset'],
        'category.delete' => [self::CATEGORY_MASTER, 'Penghapusan kategori aset'],
        'category.import' => [self::CATEGORY_MASTER, 'Impor kategori aset dari Excel'],
        'category.import_edits' => [self::CATEGORY_MASTER, 'Edit massal kategori aset dari Excel'],
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

    /** The master entity of a master data action (department, location, category). */
    public static function entity(string $action): string
    {
        return explode('.', $action)[0];
    }
}
