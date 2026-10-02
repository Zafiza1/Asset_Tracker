<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limits live in config/platform.php (rate_limits) so each
        // environment — and individual tests — can tune them.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(config('platform.rate_limits.auth'))->by($request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('platform.rate_limits.api'))->by($this->rateLimitKey($request));
        });

        RateLimiter::for('api-write', function (Request $request) {
            return Limit::perMinute(config('platform.rate_limits.api_write'))->by($this->rateLimitKey($request));
        });

        // Higher limit: automated hardware/gateway traffic.
        RateLimiter::for('integration', function (Request $request) {
            return Limit::perMinute(config('platform.rate_limits.integration'))->by($this->rateLimitKey($request));
        });
    }

    /**
     * Authenticated callers are limited per user, anonymous ones per IP.
     */
    protected function rateLimitKey(Request $request): string
    {
        if ($apiKey = $request->attributes->get('api_key')) {
            return 'api-key:' . $apiKey->id;
        }

        return (string) ($request->user()?->id ?: $request->ip());
    }
}
