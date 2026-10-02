<?php

use App\Services\ModuleRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('modules:sync', function (ModuleRegistry $registry) {
    $count = $registry->syncFromConfig();
    $this->info("Synced {$count} modules from config/modules.php");
})->purpose('Publish the module catalog in config/modules.php to the module registry');

// Re-queue webhook deliveries whose retry time has come (requires the
// scheduler: `php artisan schedule:work`, see docker-compose.yml).
Schedule::job(new \App\Jobs\RetryFailedWebhookDeliveriesJob())->everyFiveMinutes()->withoutOverlapping();
