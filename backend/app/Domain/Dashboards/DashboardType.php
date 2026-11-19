<?php

namespace App\Domain\Dashboards;

/**
 * Kinds of dashboards. One Overview per organization (never deleted), one dashboard per asset
 * category (data filtered to that category), and any number of custom dashboards.
 */
final class DashboardType
{
    public const OVERVIEW = 'overview';

    public const CATEGORY = 'category';

    public const CUSTOM = 'custom';

    /** The member's own asset-management board: a personal copy of their role's template (F-06 §4.1). */
    public const ASSET_BOARD = 'asset_board';

    public const ALL = [self::OVERVIEW, self::CATEGORY, self::CUSTOM, self::ASSET_BOARD];

    /** Personal: only the owner sees it. Organization: members per DashboardPolicy and shares. */
    public const PERSONAL = 'personal';

    public const ORGANIZATION = 'organization';

    public const VISIBILITIES = [self::PERSONAL, self::ORGANIZATION];
}
