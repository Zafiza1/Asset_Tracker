<?php

namespace App\Providers;

use App\Events\AssetCreated;
use App\Events\AssetDeleted;
use App\Events\AssetLocationUpdated;
use App\Events\AssetStatusChanged;
use App\Events\AssetUpdated;
use App\Events\ModuleLifecycleChanged;
use App\Listeners\DispatchWebhookDeliveries;
use App\Listeners\LogAssetActivity;
use App\Listeners\LogAuthenticationActivity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],

        Login::class => [
            LogAuthenticationActivity::class,
        ],

        Logout::class => [
            LogAuthenticationActivity::class,
        ],

        Failed::class => [
            LogAuthenticationActivity::class,
        ],

        AssetCreated::class => [
            LogAssetActivity::class,
            DispatchWebhookDeliveries::class,
        ],

        AssetUpdated::class => [
            LogAssetActivity::class,
            DispatchWebhookDeliveries::class,
        ],

        AssetDeleted::class => [
            LogAssetActivity::class,
            DispatchWebhookDeliveries::class,
        ],

        AssetLocationUpdated::class => [
            DispatchWebhookDeliveries::class,
        ],

        AssetStatusChanged::class => [
            DispatchWebhookDeliveries::class,
        ],

        ModuleLifecycleChanged::class => [
            DispatchWebhookDeliveries::class,
        ],
    ];

    public function boot(): void
    {
        //
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
