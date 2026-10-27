<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which organizations an account may work in, and which one is active in its session.
 *
 * An organization is accessible when the account is active, its membership there is
 * active, and the organization itself is active. The active organization lives in the
 * server-side session; request input can never select it, only switch() can, and only
 * to an accessible organization. Every read re-checks access, so suspension is immediate.
 */
final class OrganizationAccess
{
    public const SESSION_KEY = 'organization_id';

    public function __construct(private readonly Tenancy $tenancy) {}

    /** @return Collection<int, Organization> */
    public function accessible(User $user): Collection
    {
        if (! $user->isTenantUser() || ! $user->isActive()) {
            return collect();
        }

        return $this->tenancy->runAsSystem(fn () => $this->accessibleQuery($user)->orderBy('name')->get());
    }

    public function find(User $user, string $organizationId): ?Organization
    {
        if (! $user->isTenantUser() || ! $user->isActive()) {
            return null;
        }

        return $this->tenancy->runAsSystem(fn () => $this->accessibleQuery($user)->whereKey($organizationId)->first());
    }

    /**
     * The organization stored in the session, re-validated. When the session holds none yet,
     * the only accessible organization (or the last one used) is selected automatically.
     * Returns null when the user must choose, or when the stored one is no longer accessible.
     * Without a session (stateless API client) only the automatic choice applies.
     */
    public function current(?Session $session, User $user): ?Organization
    {
        $stored = $session?->get(self::SESSION_KEY);
        if (is_string($stored)) {
            return $this->find($user, $stored);
        }

        $default = $this->defaultFor($user);
        if ($default !== null) {
            $session?->put(self::SESSION_KEY, $default->id);
        }

        return $default;
    }

    public function switch(Session $session, User $user, Organization $organization): void
    {
        $session->put(self::SESSION_KEY, $organization->id);
        $session->migrate(true);
        if ($user->last_organization_id !== $organization->id) {
            $this->tenancy->runAsSystem(fn () => $user->forceFill(['last_organization_id' => $organization->id])->save());
        }
    }

    public function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /** True when the account belongs to no organization other than $organizationId (any status). */
    public function isExclusiveTo(User $user, string $organizationId): bool
    {
        return ! $this->tenancy->runAsSystem(fn () => OrganizationMember::query()
            ->where('user_id', $user->id)
            ->where('organization_id', '!=', $organizationId)
            ->exists());
    }

    private function defaultFor(User $user): ?Organization
    {
        $organizations = $this->accessible($user);
        if ($organizations->count() === 1) {
            return $organizations->first();
        }

        return $organizations->firstWhere('id', $user->last_organization_id);
    }

    /** @return Builder<Organization> */
    private function accessibleQuery(User $user): Builder
    {
        return Organization::query()
            ->where('status', Organization::STATUS_ACTIVE)
            ->whereIn('id', OrganizationMember::query()->select('organization_id')
                ->where('user_id', $user->id)
                ->where('status', User::STATUS_ACTIVE));
    }
}
