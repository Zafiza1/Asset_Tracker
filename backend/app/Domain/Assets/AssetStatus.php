<?php

namespace App\Domain\Assets;

/**
 * Operational status of an asset. Archiving ("nonaktif") is separate from status:
 * an archived asset keeps its last status and history but leaves the active inventory.
 *
 * The stored keys predate the current labels: in_use is in service ("Aktif"), available is
 * out of service, not in anyone's use ("Tidak Aktif").
 */
final class AssetStatus
{
    public const AVAILABLE = 'available';

    public const IN_USE = 'in_use';

    public const ON_LOAN = 'on_loan';

    public const UNDER_MAINTENANCE = 'under_maintenance';

    public const LOST = 'lost';

    public const DISPOSED = 'disposed';

    public const ALL = [self::IN_USE, self::AVAILABLE, self::UNDER_MAINTENANCE, self::ON_LOAN, self::LOST, self::DISPOSED];

    /** Statuses a user may pick directly; on_loan is set only by a loan transaction. */
    public const SELECTABLE = [self::IN_USE, self::AVAILABLE, self::UNDER_MAINTENANCE, self::LOST, self::DISPOSED];

    /** Statuses allowed when a new asset is registered. */
    public const INITIAL = [self::IN_USE, self::AVAILABLE, self::UNDER_MAINTENANCE];

    public const LABELS = [
        self::IN_USE => 'Aktif',
        self::AVAILABLE => 'Tidak Aktif',
        self::UNDER_MAINTENANCE => 'Dalam Perbaikan',
        self::ON_LOAN => 'Dipinjamkan',
        self::LOST => 'Hilang',
        self::DISPOSED => 'Dihapuskan',
    ];
}
