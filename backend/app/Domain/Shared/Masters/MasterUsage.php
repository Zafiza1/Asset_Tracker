<?php

namespace App\Domain\Shared\Masters;

/**
 * "Is this master record still used by operational data?" Implemented by the module that
 * owns that data (Assets), so master services can refuse to archive referenced records
 * without depending on that module directly.
 */
interface MasterUsage
{
    public const CATEGORY = 'category';

    public const LOCATION = 'location';

    public function inUse(string $master, string $id): bool;
}
