<?php

namespace App\Domain\Dashboards;

/**
 * What a share grants on an organization dashboard. Each level includes the ones below it:
 * view (open it), edit (widgets and layout), manage (settings, sharing, deleting).
 * None of them grants the data the widgets show: widgets check the data permissions.
 */
final class SharePermission
{
    public const VIEW = 'view';

    public const EDIT = 'edit';

    public const MANAGE = 'manage';

    public const ALL = [self::VIEW, self::EDIT, self::MANAGE];

    private const RANK = [self::VIEW => 1, self::EDIT => 2, self::MANAGE => 3];

    /** Does $granted include $required? Unknown levels include nothing. */
    public static function includes(?string $granted, string $required): bool
    {
        return isset(self::RANK[$granted ?? ''], self::RANK[$required]) && self::RANK[$granted] >= self::RANK[$required];
    }

    /** The highest of the given levels, or null when there are none. */
    public static function highest(iterable $levels): ?string
    {
        $best = null;
        foreach ($levels as $level) {
            if (isset(self::RANK[$level]) && ($best === null || self::RANK[$level] > self::RANK[$best])) {
                $best = $level;
            }
        }

        return $best;
    }
}
