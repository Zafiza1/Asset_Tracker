<?php

use App\Services\ModuleRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('modules:sync', function (ModuleRegistry $registry) {
    $count = $registry->syncFromConfig();
    $this->info("Synced {$count} modules from config/modules.php");
})->purpose('Publish the module catalog in config/modules.php to the module registry');
