<?php

namespace App\Domain\Shared\Masters;

/**
 * Status of master records. Referenced masters are never hard-deleted: they are
 * deactivated (hidden from new selections) or archived (also hidden from lists).
 */
final class MasterStatus
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const ARCHIVED = 'archived';

    public const ALL = [self::ACTIVE, self::INACTIVE, self::ARCHIVED];

    /** Statuses settable through a normal update; archiving has its own action and permission. */
    public const EDITABLE = [self::ACTIVE, self::INACTIVE];
}
