<?php

namespace App\Modules\Inspection;

use App\Listeners\DispatchWebhookDeliveries;
use App\Modules\Inspection\Events\InspectionChanged;
use App\Modules\Inspection\Models\Inspection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the Inspection module into the platform: its routes (guarded by
 * `module:inspection`), policy and events. Registered through
 * config/modules.php `providers`.
 */
class InspectionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Inspection::class, InspectionPolicy::class);

        Event::listen(InspectionChanged::class, DispatchWebhookDeliveries::class);

        if (!$this->app->routesAreCached()) {
            Route::prefix('api/v1/inspections')
                ->middleware(['api', 'auth:sanctum', 'tenant', 'module:' . InspectionService::MODULE_SLUG])
                ->group(__DIR__ . '/routes.php');
        }
    }
}
