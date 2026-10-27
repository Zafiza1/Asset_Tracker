<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Services\Licensing;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant routes, after ResolveTenant: without a base license in force the organization is
 * read-only, and so it is while it uses more seats than licensed (a seat pack expired or was
 * revoked). Reads always pass. The actions that free seats (suspending or deactivating a member,
 * revoking an invitation) are excluded from this middleware in routes/api.php.
 */
final class EnsureLicensed
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Licensing $licensing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $status = $this->licensing->status($this->tenancy->organizationId());
        if (! $status->active) {
            throw Licensing::inactive();
        }
        if ($status->overLimit()) {
            throw new ApiException('SEAT_LIMIT_EXCEEDED',
                "Organisasi memakai {$status->used()} seat, melebihi lisensi ({$status->capacity}). Nonaktifkan anggota atau batalkan undangan sampai sesuai, atau tambah seat lisensi.",
                403, ['seats' => $status->toArray()['seats']]);
        }

        return $next($request);
    }
}
