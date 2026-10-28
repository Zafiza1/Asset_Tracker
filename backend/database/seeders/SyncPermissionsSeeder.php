<?php

namespace Database\Seeders;

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Seeder;

/** Sync permissions using system context (required for system tables) */
class SyncPermissionsSeeder extends Seeder
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {}

    public function run(): void
    {
        $this->tenancy->runAsSystem(function () {
            PermissionCatalog::sync();
        });

        $this->command->info('Permissions synced successfully.');
    }
}
