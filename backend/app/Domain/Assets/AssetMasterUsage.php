<?php

namespace App\Domain\Assets;

use App\Domain\Assets\Models\Asset;
use App\Domain\Shared\Masters\MasterUsage;

/** A master record is in use while an active (non-archived) asset references it. */
final class AssetMasterUsage implements MasterUsage
{
    private const COLUMNS = [
        self::CATEGORY => 'category_id',
        self::LOCATION => 'location_id',
        self::CONDITION => 'condition_id',
    ];

    public function inUse(string $master, string $id): bool
    {
        return Asset::query()->where(self::COLUMNS[$master], $id)->whereNull('archived_at')->exists();
    }
}
