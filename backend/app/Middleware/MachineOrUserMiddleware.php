<?php

namespace App\Middleware;

use App\Services\ApiKeyService;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates ingestion routes used by both machines and people.
 *
 * - X-Api-Key present: the key alone defines the tenant (its organization
 *   and project); headers cannot widen it. The key is exposed to the
 *   controller as the "api_key" request attribute.
 * - Otherwise: a Sanctum user token, followed by the normal TenantMiddleware
 *   context resolution and validation.
 */
class MachineOrUserMiddleware
{
    public function __construct(
        protected ApiKeyService $keys,
        protected TenantMiddleware $tenant
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->header('X-Api-Key');

        if ($presented !== null) {
            $key = $this->keys->authenticate((string) $presented);

            if (!$key) {
                return response()->json(['success' => false, 'message' => 'Invalid or revoked API key'], 401);
            }

            TenantScope::clearContext();
            TenantScope::setOrganizationId($key->organization_id);
            TenantScope::setProjectId($key->project_id);
            $request->attributes->set('api_key', $key);

            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $this->tenant->handle($request, $next);
    }
}
