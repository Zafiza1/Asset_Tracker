<?php

namespace App\Modules\Inventory;

use App\Events\AssetLocationUpdated;
use App\Listeners\DispatchWebhookDeliveries;
use App\Modules\Inventory\Events\InventoryEvent;
use App\Modules\Inventory\Listeners\CheckLowStock;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the Inventory module into the platform: its routes (guarded by
 * `module:inventory`), its events, and a listener on Core's
 * asset.location.updated for low-stock alerts. Registered through
 * config/modules.php `providers`.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(InventoryEvent::class, DispatchWebhookDeliveries::class);
        Event::listen(AssetLocationUpdated::class, CheckLowStock::class);

        if (!$this->app->routesAreCached()) {
            Route::prefix('api/v1/inventory')
                ->middleware(['api', 'auth:sanctum', 'tenant', 'module:' . InventoryService::MODULE_SLUG])
                ->group(__DIR__ . '/routes.php');
        }
    }
}
