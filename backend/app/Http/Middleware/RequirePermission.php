<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('permission:asset.view') or 'permission:a,b' (all required).
 * Checks the tenant context, or the platform context on platform routes.
 */
final class RequirePermission
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $context = $this->tenancy->hasTenant() ? $this->tenancy->tenant() : $this->tenancy->platform();

        if ($context === null) {
            throw ApiException::forbidden();
        }
        foreach ($permissions as $permission) {
            if (! $context->can($permission)) {
                throw ApiException::forbidden();
            }
        }

        return $next($request);
    }
}
