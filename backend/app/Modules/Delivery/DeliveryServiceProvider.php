<?php

namespace App\Modules\Delivery;

use App\Listeners\DispatchWebhookDeliveries;
use App\Modules\Delivery\Events\DeliveryChanged;
use App\Modules\Delivery\Models\Delivery;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the Delivery module into the platform: its routes (guarded by
 * `module:delivery`), policy and events. Registered through
 * config/modules.php `providers`.
 */
class DeliveryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Delivery::class, DeliveryPolicy::class);

        Event::listen(DeliveryChanged::class, DispatchWebhookDeliveries::class);

        if (!$this->app->routesAreCached()) {
            Route::prefix('api/v1/deliveries')
                ->middleware(['api', 'auth:sanctum', 'tenant', 'module:' . DeliveryService::MODULE_SLUG])
                ->group(__DIR__ . '/routes.php');
        }
    }
}
