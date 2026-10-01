<?php

namespace Database\Seeders;

use App\Services\ModuleRegistry;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(ModuleRegistry $registry): void
    {
        $count = $registry->syncFromConfig();

        $this->command->info("Module catalog synced ({$count} modules).");
    }
}
