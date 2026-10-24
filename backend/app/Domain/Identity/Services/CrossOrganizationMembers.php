<?php

namespace App\Domain\Identity\Services;

use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\OrganizationInvitation;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Invites one email address to several organizations in a single step. An administrator
 * may invite only to organizations where they themselves hold user.create and role.manage;
 * every invitation is created by OrganizationInvitations inside that organization's own
 * tenant context, so RLS, role checks and audit work exactly as for a single organization.
 * All invitations of one request succeed or fail together. Nobody gains access before the
 * owner of the address accepts each invitation.
 */
final class CrossOrganizationMembers
{
    public const MANAGE_PERMISSIONS = OrganizationInvitations::MANAGE_PERMISSIONS;

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly OrganizationAccess $access,
        private readonly OrganizationInvitations $invitations,
    ) {}

    /**
     * Organizations where $actor may add members, with their roles (current organization first).
     *
     * @return Collection<int, array{organization: Organization, roles: Collection<int, Role>}>
     */
    public function options(User $actor): Collection
    {
        return $this->organizationsWhere($actor, self::MANAGE_PERMISSIONS)
            ->map(fn (Organization $o) => [
                'organization' => $o,
                'roles' => $this->inOrganization($actor, $o, fn () => Role::query()->withCount('permissions')->orderBy('name')->get()),
            ]);
    }

    /**
     * The memberships of $user in organizations where $actor may view users.
     *
     * @return Collection<int, array{organization: Organization, member: User}>
     */
    public function memberships(User $actor, User $user): Collection
    {
        return $this->organizationsWhere($actor, ['user.view'])
            ->map(fn (Organization $o) => [
                'organization' => $o,
                'member' => $this->inOrganization($actor, $o, fn () => User::query()->ofCurrentOrganization()
                    ->with('roles', 'membership')->whereKey($user->id)->first()),
            ])
            ->filter(fn (array $row) => $row['member'] !== null)
            ->values();
    }

    /**
     * Invites $email to every organization in $grants. Organization-specific fields (home
     * department, data scopes) only apply to the organization they were given for.
     *
     * @param  array<string, mixed>  $profile  membership fields
     * @param  list<array{organization_id: string, role_ids: list<string>, scopes?: list<array{scope_type: string, ref_id?: string|null}>}>  $grants
     * @return array<string, OrganizationInvitation> by organization id, roles attached
     */
    public function invite(User $actor, string $email, array $profile, array $grants): array
    {
        $organizations = $this->resolveTargets($actor, $grants);
        $currentId = $this->tenancy->organizationId();

        return DB::transaction(function () use ($actor, $email, $profile, $grants, $organizations, $currentId) {
            $invitations = [];
            foreach ($grants as $grant) {
                $organization = $organizations[$grant['organization_id']];
                $orgProfile = $organization->id === $currentId ? $profile : array_diff_key($profile, ['home_department_id' => true]);
                $invitations[$organization->id] = $this->inOrganization($actor, $organization, fn () => $this->labelled($organization, function () use ($email, $orgProfile, $grant) {
                    $invitation = $this->invitations->create($email, $orgProfile, $grant['role_ids'], $grant['scopes'] ?? [['scope_type' => 'organization']]);
                    $this->invitations->withRoles([$invitation]);

                    return $invitation;
                }));
            }

            return $invitations;
        });
    }

    /**
     * Invites an existing member of the current organization to another organization; the
     * member gains access there only after accepting.
     *
     * @param  list<string>  $roleIds
     */
    public function inviteMember(User $actor, User $user, string $organizationId, array $roleIds): OrganizationInvitation
    {
        $membership = $user->membership()->first();

        return $this->invite($actor, $user->email, [
            'job_title' => $membership?->job_title,
            'employee_number' => $membership?->employee_number,
        ], [['organization_id' => $organizationId, 'role_ids' => $roleIds]])[$organizationId];
    }

    /**
     * @param  list<string>  $permissions
     * @return Collection<int, Organization>
     */
    private function organizationsWhere(User $actor, array $permissions): Collection
    {
        $currentId = $this->tenancy->organizationId();

        return $this->access->accessible($actor)
            ->filter(fn (Organization $o) => $this->inOrganization($actor, $o,
                fn () => collect($permissions)->every(fn (string $p) => $this->tenancy->tenant()->can($p))))
            ->sortBy(fn (Organization $o) => $o->id === $currentId ? 0 : 1)
            ->values();
    }

    /**
     * @param  list<array{organization_id: string}>  $grants
     * @return array<string, Organization>
     */
    private function resolveTargets(User $actor, array $grants): array
    {
        $manageable = $this->organizationsWhere($actor, self::MANAGE_PERMISSIONS)->keyBy('id');
        $targets = [];
        foreach ($grants as $grant) {
            $targets[$grant['organization_id']] = $manageable->get($grant['organization_id'])
                ?? throw ApiException::forbidden('ORGANIZATION_ACCESS_DENIED', 'Anda tidak dapat menambahkan pengguna ke organisasi tersebut.');
        }

        return $targets;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function inOrganization(User $actor, Organization $organization, Closure $callback): mixed
    {
        if ($organization->id === $this->tenancy->organizationId()) {
            return $callback();
        }

        return $this->tenancy->runAsTenant(new TenantContext($organization, $actor), $callback);
    }

    /**
     * Prefixes business-rule errors with the organization name so a multi-organization
     * request tells the administrator which organization rejected it.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function labelled(Organization $organization, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiException $e) {
            throw new ApiException($e->errorCode, "{$organization->name}: {$e->getMessage()}", $e->status, $e->details);
        }
    }
}
