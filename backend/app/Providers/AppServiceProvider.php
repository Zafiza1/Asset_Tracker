<?php

namespace App\Providers;

use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Route parameters that are always UUID primary keys (invalid ids → 404, never a SQL error). */
    private const UUID_ROUTE_PARAMETERS = ['user', 'role', 'organization'];

    public function register(): void
    {
        $this->app->singleton(Tenancy::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        foreach (self::UUID_ROUTE_PARAMETERS as $parameter) {
            Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(5)->by('sensitive:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by('api:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Each queued job starts without any tenant context; jobs establish it explicitly.
        Queue::before(fn () => $this->app->make(Tenancy::class)->reset());
        Queue::after(fn () => $this->app->make(Tenancy::class)->reset());
    }
}
