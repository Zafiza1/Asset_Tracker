<?php

namespace App\Tenancy;

/**
 * Holds the resolved organization/project for the current request.
 *
 * Bound as a singleton (see AppServiceProvider) so it lives for exactly one
 * request lifecycle. It is populated by TenantMiddleware after validating the
 * requesting user's membership, then read by TenantScope (the Eloquent global
 * scope) to constrain every tenant-aware query automatically.
 */
class TenantContext
{
    protected ?int $organizationId = null;

    protected ?int $projectId = null;

    public function setOrganization(?int $organizationId): static
    {
        $this->organizationId = $organizationId;

        return $this;
    }

    public function setProject(?int $projectId): static
    {
        $this->projectId = $projectId;

        return $this;
    }

    public function organizationId(): ?int
    {
        return $this->organizationId;
    }

    public function projectId(): ?int
    {
        return $this->projectId;
    }

    public function hasOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    public function hasProject(): bool
    {
        return $this->projectId !== null;
    }

    public function clear(): static
    {
        $this->organizationId = null;
        $this->projectId = null;

        return $this;
    }
}
