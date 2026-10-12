<?php

namespace App\Domain\Assets;

/**
 * Operational status of an asset. Archiving ("nonaktif") is separate from status:
 * an archived asset keeps its last status and history but leaves the active inventory.
 */
final class AssetStatus
{
    public const AVAILABLE = 'available';

    public const IN_USE = 'in_use';

    public const ON_LOAN = 'on_loan';

    public const UNDER_MAINTENANCE = 'under_maintenance';

    public const DISPOSED = 'disposed';

    public const ALL = [self::AVAILABLE, self::IN_USE, self::ON_LOAN, self::UNDER_MAINTENANCE, self::DISPOSED];

    /** Statuses a user may pick directly; on_loan is set only by a loan transaction. */
    public const SELECTABLE = [self::AVAILABLE, self::IN_USE, self::UNDER_MAINTENANCE, self::DISPOSED];

    /** Statuses allowed when a new asset is registered. */
    public const INITIAL = [self::AVAILABLE, self::IN_USE, self::UNDER_MAINTENANCE];

    public const LABELS = [
        self::AVAILABLE => 'Tersedia',
        self::IN_USE => 'Digunakan',
        self::ON_LOAN => 'Dipinjam',
        self::UNDER_MAINTENANCE => 'Dalam perbaikan',
        self::DISPOSED => 'Dihapuskan',
    ];
}
