<?php

namespace App\Domain\Shared\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Holds the execution context of the current request/job and mirrors it into the
 * PostgreSQL session settings used by Row-Level Security policies.
 *
 * Modes:
 *  - none:     no context. Tenant-owned models refuse to query (fail-closed) and RLS hides all rows.
 *  - tenant:   exactly one organization; queries are filtered to it.
 *  - platform: authenticated platform user; cross-tenant access allowed by explicit platform permissions.
 *  - system:   internal code paths (seeders, console, auth bootstrap). Never derived from request input.
 */
final class Tenancy
{
    private ?TenantContext $tenant = null;

    private ?PlatformContext $platform = null;

    private bool $system = false;

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): TenantContext
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    public function organizationId(): string
    {
        return $this->tenant()->organizationId();
    }

    public function platform(): ?PlatformContext
    {
        return $this->platform;
    }

    /** True when no tenant filter should be applied (platform or system mode). */
    public function isUnrestricted(): bool
    {
        return $this->tenant === null && ($this->system || $this->platform !== null);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsTenant(TenantContext $context, Closure $callback): mixed
    {
        return $this->run(new TenancyState($context, null, false), $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsPlatform(PlatformContext $context, Closure $callback): mixed
    {
        return $this->run(new TenancyState(null, $context, false), $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsSystem(Closure $callback): mixed
    {
        return $this->run(new TenancyState(null, $this->platform, true), $callback);
    }

    /** Switch state and return the previous one (for callers that cannot use a closure). */
    public function enter(TenancyState $state): TenancyState
    {
        $previous = new TenancyState($this->tenant, $this->platform, $this->system);
        $this->apply($state);

        return $previous;
    }

    public function restore(TenancyState $state): void
    {
        $this->apply($state);
    }

    public function reset(): void
    {
        $this->apply(new TenancyState(null, null, false));
    }

    private function run(TenancyState $state, Closure $callback): mixed
    {
        $previous = $this->enter($state);

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    private function apply(TenancyState $state): void
    {
        $this->tenant = $state->tenant;
        $this->platform = $state->platform;
        $this->system = $state->system;

        $orgId = $state->tenant?->organizationId() ?? '';
        $platform = $state->tenant === null && ($state->system || $state->platform !== null) ? 'on' : 'off';

        // Session-level (is_local = false) so it also applies outside explicit transactions;
        // every state change rewrites both settings, so nothing leaks between contexts.
        DB::select("SELECT set_config('app.organization_id', ?, false), set_config('app.platform_context', ?, false)", [$orgId, $platform]);
    }
}
