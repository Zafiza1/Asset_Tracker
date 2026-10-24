<?php

namespace App\Providers;

use App\Domain\Assets\AssetMasterUsage;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\EmailVerification;
use App\Domain\Shared\Masters\MasterUsage;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Route parameters that are always UUID primary keys (invalid ids → 404, never a SQL error). */
    private const UUID_ROUTE_PARAMETERS = ['user', 'role', 'organization', 'invitation'];

    public function register(): void
    {
        $this->app->singleton(Tenancy::class);
        $this->app->bind(MasterUsage::class, AssetMasterUsage::class);
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
        // Each sign-up creates a whole organization: keep it rare per client.
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(3)->by('register:'.$request->ip()));
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(5)->by('sensitive:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by('api:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Invitation tokens are unguessable; this only keeps probing and sign-up bursts down.
        RateLimiter::for('invitation', fn (Request $request) => Limit::perMinute(10)->by('invitation:'.$request->ip()));
        // Each request sends an email.
        RateLimiter::for('verification', fn (Request $request) => Limit::perMinutes(10, 3)->by('verification:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Verification emails link to the SPA, which confirms the signed link while logged in.
        VerifyEmail::createUrlUsing(fn (User $user) => EmailVerification::link($user));
        VerifyEmail::toMailUsing(fn (User $user, string $url) => (new MailMessage)
            ->subject('Verifikasi alamat email Anda')
            ->greeting("Halo {$user->name},")
            ->line('Klik tombol di bawah ini untuk memverifikasi alamat email akun Asset Tracker Anda.')
            ->action('Verifikasi email', $url)
            ->line('Tautan berlaku '.config('auth.verification.expire').' menit dan hanya bekerja saat Anda masuk dengan akun ini.')
            ->line('Abaikan email ini bila Anda tidak membuat akun.'));

        // Each queued job starts without any tenant context; jobs establish it explicitly.
        Queue::before(fn () => $this->app->make(Tenancy::class)->reset());
        Queue::after(fn () => $this->app->make(Tenancy::class)->reset());
    }
}
