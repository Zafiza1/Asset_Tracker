<?php

namespace App\Modules\Customer;

use App\Listeners\DispatchWebhookDeliveries;
use App\Modules\Customer\Events\CustomerChanged;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the Customer module into the platform: its routes (guarded by
 * `module:customer`), policy and events. Registered through
 * config/modules.php `providers`.
 */
class CustomerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);

        Event::listen(CustomerChanged::class, DispatchWebhookDeliveries::class);

        if (!$this->app->routesAreCached()) {
            Route::prefix('api/v1/customers')
                ->middleware(['api', 'auth:sanctum', 'tenant', 'module:' . CustomerService::MODULE_SLUG])
                ->group(__DIR__ . '/routes.php');
        }
    }
}
