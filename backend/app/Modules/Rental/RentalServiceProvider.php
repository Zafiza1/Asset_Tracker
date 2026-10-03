<?php

namespace App\Modules\Rental;

use App\Listeners\DispatchWebhookDeliveries;
use App\Modules\Rental\Events\RentalChanged;
use App\Modules\Rental\Models\Rental;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the Rental module into the platform: its routes (guarded by
 * `module:rental`), policy and events. Registered through
 * config/modules.php `providers`.
 */
class RentalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Rental::class, RentalPolicy::class);

        Event::listen(RentalChanged::class, DispatchWebhookDeliveries::class);

        if (!$this->app->routesAreCached()) {
            Route::prefix('api/v1/rentals')
                ->middleware(['api', 'auth:sanctum', 'tenant', 'module:' . RentalService::MODULE_SLUG])
                ->group(__DIR__ . '/routes.php');
        }
    }
}
