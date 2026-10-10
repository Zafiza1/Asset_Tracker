<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant context for /api/* tenant routes.
 *
 * The organization always comes from the authenticated account (one user = one company);
 * nothing in the request (header, query, body) can select or override it. User and
 * organization status are re-checked on every request so revocation is immediate.
 */
final class ResolveTenant
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException;
        }

        // Fresh read: the session copy may be stale after suspension or role changes.
        $user = $this->tenancy->runAsSystem(fn () => User::query()->with('organization')->find($user->getKey()));

        if ($user === null || ! $user->isActive()) {
            $this->terminateSession($request, $user);
            throw new ApiException('ACCOUNT_INACTIVE', 'Akun Anda tidak aktif.', 401);
        }
        if (! $user->isTenantUser()) {
            throw ApiException::forbidden('TENANT_USER_REQUIRED', 'Endpoint ini hanya untuk pengguna organisasi.');
        }

        /** @var Organization $organization */
        $organization = $user->organization;
        if (! $organization->isActive()) {
            throw ApiException::forbidden('ORGANIZATION_INACTIVE', 'Organisasi Anda sedang tidak aktif.');
        }
        if ($user->must_change_password) {
            throw ApiException::forbidden('PASSWORD_CHANGE_REQUIRED', 'Anda harus mengganti password sebelum melanjutkan.');
        }

        Auth::setUser($user);

        return $this->tenancy->runAsTenant(new TenantContext($organization, $user), fn () => $next($request));
    }

    private function terminateSession(Request $request, ?User $freshUser): void
    {
        if ($freshUser !== null) {
            Auth::guard('web')->setUser($freshUser);
            Auth::guard('web')->logout();
        }
        if ($request->hasSession()) {
            $request->session()->invalidate();
        }
    }
}
