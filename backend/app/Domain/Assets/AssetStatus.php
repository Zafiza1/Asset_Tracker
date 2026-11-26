<?php

namespace App\Domain\Assets;

use App\Domain\TenantMaster\StandardCategories;

/**
 * Operational status of an asset. Archiving ("nonaktif") is separate from status:
 * an archived asset keeps its last status and history but leaves the active inventory.
 *
 * The stored keys predate the current labels: in_use is in service ("Aktif"), available is
 * not in anyone's use, ready to be used ("Tersedia"; labelled "Tidak Aktif" from 2026-11-05 to
 * 2026-11-23, which read like the separate archiving action "Nonaktifkan").
 *
 * A cylinder (category Tabung) is in the warehouse or with a customer and never "in use" by the
 * organization itself: it has no status Aktif, and its labels say where it is (CYLINDER_LABELS).
 */
final class AssetStatus
{
    public const AVAILABLE = 'available';

    public const IN_USE = 'in_use';

    public const ON_LOAN = 'on_loan';

    public const UNDER_MAINTENANCE = 'under_maintenance';

    public const LOST = 'lost';

    public const DISPOSED = 'disposed';

    /** Temporarily outside the organization (outgoing transaction, temporary); back with an incoming one. */
    public const OFF_SITE = 'off_site';

    public const ALL = [self::IN_USE, self::AVAILABLE, self::UNDER_MAINTENANCE, self::ON_LOAN, self::OFF_SITE, self::LOST, self::DISPOSED];

    /** Statuses a user may pick directly; on_loan and off_site are set only by their transactions. */
    public const SELECTABLE = [self::IN_USE, self::AVAILABLE, self::UNDER_MAINTENANCE, self::LOST, self::DISPOSED];

    /** Statuses allowed when a new asset is registered. */
    public const INITIAL = [self::IN_USE, self::AVAILABLE, self::UNDER_MAINTENANCE];

    public const LABELS = [
        self::IN_USE => 'Aktif',
        self::AVAILABLE => 'Tersedia',
        self::UNDER_MAINTENANCE => 'Dalam Perbaikan',
        self::ON_LOAN => 'Dipinjamkan',
        self::OFF_SITE => 'Di Luar Business Unit',
        self::LOST => 'Hilang',
        self::DISPOSED => 'Dihapuskan',
    ];

    /** What these statuses mean for a cylinder. */
    public const CYLINDER_LABELS = [
        self::AVAILABLE => 'Di Gudang',
        self::OFF_SITE => 'Di Customer / Dikirim',
    ];

    /** Former labels still understood on import, so spreadsheets filled in earlier keep working (lower case). */
    public const FORMER_LABELS = [
        'tidak aktif' => self::AVAILABLE,
    ];

    /** The status of one asset. */
    public static function label(string $status, bool $cylinder = false): string
    {
        return ($cylinder ? self::CYLINDER_LABELS[$status] ?? null : null) ?? self::LABELS[$status] ?? $status;
    }

    /**
     * For lists, filters and totals, which in the PT SIG / PT CDO edition mix cylinders and vehicles.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        if (! in_array(StandardCategories::CYLINDER, config('edition.categories'), true)) {
            return self::LABELS;
        }

        return [
            ...self::LABELS,
            self::AVAILABLE => self::LABELS[self::AVAILABLE].' / '.self::CYLINDER_LABELS[self::AVAILABLE],
            self::OFF_SITE => self::CYLINDER_LABELS[self::OFF_SITE],
        ];
    }

    /** The status a label names (any of the labels above, whatever its case), or null. */
    public static function fromLabel(string $label): ?string
    {
        $label = mb_strtolower(trim($label));
        foreach ([self::LABELS, self::CYLINDER_LABELS, self::labels()] as $labels) {
            $status = array_search($label, array_map(mb_strtolower(...), $labels), true);
            if ($status !== false) {
                return $status;
            }
        }

        return self::FORMER_LABELS[$label] ?? null;
    }
}
