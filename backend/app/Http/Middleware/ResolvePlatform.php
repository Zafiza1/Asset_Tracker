<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\PlatformContext;
use App\Domain\Shared\Tenancy\Tenancy;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the platform context for /api/platform/* routes. Only platform accounts
 * qualify; tenant administrators can never reach these routes.
 */
final class ResolvePlatform
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException;
        }

        $user = $this->tenancy->runAsSystem(fn () => User::query()->find($user->getKey()));

        if ($user === null || ! $user->isActive()) {
            if ($user !== null) {
                Auth::guard('web')->setUser($user);
                Auth::guard('web')->logout();
            }
            $request->session()->invalidate();
            throw new ApiException('ACCOUNT_INACTIVE', 'Akun Anda tidak aktif.', 401);
        }
        if (! $user->isPlatformUser()) {
            // Indistinguishable from a missing route for tenant users.
            throw ApiException::forbidden();
        }
        if ($user->must_change_password) {
            throw ApiException::forbidden('PASSWORD_CHANGE_REQUIRED', 'Anda harus mengganti password sebelum melanjutkan.');
        }

        Auth::setUser($user);

        return $this->tenancy->runAsPlatform(new PlatformContext($user), fn () => $next($request));
    }
}
