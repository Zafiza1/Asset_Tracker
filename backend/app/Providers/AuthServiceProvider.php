<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Organization::class => \App\Policies\OrganizationPolicy::class,
        \App\Models\Project::class => \App\Policies\ProjectPolicy::class,
        \App\Models\User::class => \App\Policies\UserPolicy::class,
        \App\Models\Asset::class => \App\Policies\AssetPolicy::class,
        \App\Models\Location::class => \App\Policies\LocationPolicy::class,
        \App\Models\Movement::class => \App\Policies\MovementPolicy::class,
        \App\Models\ProjectModule::class => \App\Policies\ProjectModulePolicy::class,
        \App\Models\Template::class => \App\Policies\TemplatePolicy::class,
        \App\Models\Integration::class => \App\Policies\IntegrationPolicy::class,
        \App\Models\Device::class => \App\Policies\DevicePolicy::class,
        \App\Models\Webhook::class => \App\Policies\WebhookPolicy::class,
        \App\Models\ActivityLog::class => \App\Policies\ActivityLogPolicy::class,
        \App\Models\SecurityLog::class => \App\Policies\SecurityLogPolicy::class,
        \App\Models\EventLog::class => \App\Policies\EventLogPolicy::class,
        \App\Models\CustomField::class => \App\Policies\CustomFieldPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Implicitly grant "Super Admin" role all permissions
        Gate::before(function ($user, $ability) {
            return $user->isPlatformAdmin() ? true : null;
        });
    }
}
