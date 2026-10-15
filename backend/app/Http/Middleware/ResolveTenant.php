<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OrganizationAccess;
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
 * One account can belong to several organizations; the active one is stored in the
 * server-side session (OrganizationAccess) and re-validated on every request, so
 * suspension of the account, the membership or the organization takes effect immediately.
 * Nothing in the request can select it. The client may send X-Organization-Id with the
 * organization it believes is active; a mismatch (e.g. another tab switched organization)
 * is rejected instead of silently acting on a different organization.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly OrganizationAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException;
        }

        // Fresh read: the session copy may be stale after suspension or role changes.
        $user = $this->tenancy->runAsSystem(fn () => User::query()->find($user->getKey()));

        if ($user === null || ! $user->isActive()) {
            $this->terminateSession($request, $user);
            throw new ApiException('ACCOUNT_INACTIVE', 'Akun Anda tidak aktif.', 401);
        }
        if (! $user->isTenantUser()) {
            throw ApiException::forbidden('TENANT_USER_REQUIRED', 'Endpoint ini hanya untuk pengguna organisasi.');
        }

        if ($user->must_change_password) {
            throw ApiException::forbidden('PASSWORD_CHANGE_REQUIRED', 'Anda harus mengganti password sebelum melanjutkan.');
        }

        $session = $request->hasSession() ? $request->session() : null;
        $organization = $this->access->current($session, $user);
        if ($organization === null) {
            if ($session?->has(OrganizationAccess::SESSION_KEY)) {
                $this->access->forget($session);
                throw ApiException::forbidden('ORGANIZATION_INACTIVE', 'Organisasi ini sedang tidak aktif atau akses Anda telah dicabut.');
            }
            if ($this->access->accessible($user)->isEmpty()) {
                throw ApiException::forbidden('ORGANIZATION_INACTIVE', 'Organisasi Anda sedang tidak aktif.');
            }
            throw ApiException::conflict('ORGANIZATION_SELECTION_REQUIRED', 'Pilih organisasi terlebih dahulu.');
        }
        $expected = $request->header('X-Organization-Id');
        if ($expected !== null && $expected !== $organization->id) {
            throw ApiException::conflict('ORGANIZATION_MISMATCH', 'Organisasi aktif telah berganti. Muat ulang halaman.');
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
