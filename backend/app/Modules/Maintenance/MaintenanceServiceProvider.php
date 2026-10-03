<?php

namespace App\Modules\Maintenance;

use App\Listeners\DispatchWebhookDeliveries;
use App\Modules\Maintenance\Events\MaintenanceCompleted;
use App\Modules\Maintenance\Events\MaintenanceCreated;
use App\Modules\Maintenance\Models\MaintenanceRecord;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the Maintenance module into the platform without Core knowing about
 * it: its own routes (guarded by `module:maintenance`), policy and events.
 * Registered through config/modules.php `providers`.
 */
class MaintenanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(MaintenanceRecord::class, MaintenanceRecordPolicy::class);

        Event::listen(MaintenanceCreated::class, DispatchWebhookDeliveries::class);
        Event::listen(MaintenanceCompleted::class, DispatchWebhookDeliveries::class);

        if (!$this->app->routesAreCached()) {
            Route::prefix('api/v1/maintenance')
                ->middleware(['api', 'auth:sanctum', 'tenant', 'module:' . MaintenanceService::MODULE_SLUG])
                ->group(__DIR__ . '/routes.php');
        }
    }
}
