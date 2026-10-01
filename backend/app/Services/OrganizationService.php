<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Organization (tenant) lifecycle and membership — Control Plane, Section 4.
 *
 * Membership lives in organization_users; what a member may do comes from
 * roles granted at organization level (user_roles with project_id NULL),
 * which apply to every project of the organization.
 */
class OrganizationService
{
    public const OWNER_ROLE = 'organization-owner';

    public function __construct(protected RoleAssignment $roles)
    {
    }

    /**
     * The creator becomes the organization's first Organization Owner.
     */
    public function create(User $creator, array $data): Organization
    {
        return DB::transaction(function () use ($creator, $data) {
            $organization = Organization::create(array_merge($data, [
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'status' => 'active',
            ]));

            $organization->users()->attach($creator->id, ['role' => 'owner', 'joined_at' => now()]);
            $creator->assignRole(self::OWNER_ROLE, $organization->id);

            if (!$creator->default_organization_id) {
                $creator->forceFill(['default_organization_id' => $organization->id])->save();
            }

            return $organization;
        });
    }

    public function update(Organization $organization, array $data): Organization
    {
        $organization->update($data);

        return $organization->fresh();
    }

    /**
     * Soft-deletes the organization and its projects; data stays recoverable.
     */
    public function delete(Organization $organization): void
    {
        DB::transaction(function () use ($organization) {
            $projectIds = Project::withoutGlobalScope(TenantScope::class)
                ->where('organization_id', $organization->id)
                ->pluck('id');

            Project::withoutGlobalScope(TenantScope::class)->whereKey($projectIds)->delete();

            User::where('default_organization_id', $organization->id)
                ->update(['default_organization_id' => null, 'default_project_id' => null]);

            $organization->delete();
        });
    }

    public function addMember(Organization $organization, User $actor, User $member, ?string $roleSlug): void
    {
        if ($member->canAccessOrganization($organization->id)) {
            throw ApiException::conflict('User is already a member of this organization');
        }

        if ($roleSlug) {
            $this->roles->assertAssignable($actor, $roleSlug, $organization->id);
        }

        DB::transaction(function () use ($organization, $member, $roleSlug) {
            $organization->users()->attach($member->id, [
                'role' => $this->membershipRole($roleSlug),
                'joined_at' => now(),
            ]);

            if ($roleSlug) {
                $member->assignRole($roleSlug, $organization->id);
            }
        });
    }

    /**
     * Replace the member's organization-level role (null: plain member with
     * project-level access only).
     */
    public function changeMemberRole(Organization $organization, User $actor, User $member, ?string $roleSlug): void
    {
        $this->assertMember($organization, $member);

        if ($actor->is($member)) {
            throw ApiException::conflict('You cannot change your own role');
        }

        $this->roles->assertCanManage($actor, $member, $organization->id);

        if ($roleSlug) {
            $this->roles->assertAssignable($actor, $roleSlug, $organization->id);
        }

        if ($roleSlug !== self::OWNER_ROLE) {
            $this->assertNotLastOwner($organization, $member);
        }

        DB::transaction(function () use ($organization, $member, $roleSlug) {
            $member->syncRoles($roleSlug ? [$roleSlug] : [], $organization->id);
            $organization->users()->updateExistingPivot($member->id, [
                'role' => $this->membershipRole($roleSlug),
            ]);
        });
    }

    /**
     * Removes the member from the organization and every project in it,
     * revoking all their grants there. Members may remove themselves (leave).
     */
    public function removeMember(Organization $organization, User $actor, User $member): void
    {
        $this->assertMember($organization, $member);

        if (!$actor->is($member)) {
            $this->roles->assertCanManage($actor, $member, $organization->id);
        }

        $this->assertNotLastOwner($organization, $member);

        DB::transaction(function () use ($organization, $member) {
            $projectIds = Project::withoutGlobalScope(TenantScope::class)
                ->withTrashed()
                ->where('organization_id', $organization->id)
                ->pluck('id');

            $member->projects()->detach($projectIds);
            $organization->users()->detach($member->id);

            DB::table('user_roles')
                ->where('user_id', $member->id)
                ->where('organization_id', $organization->id)
                ->delete();
            DB::table('user_permissions')
                ->where('user_id', $member->id)
                ->where('organization_id', $organization->id)
                ->delete();

            if ($member->default_organization_id === $organization->id) {
                $member->forceFill(['default_organization_id' => null, 'default_project_id' => null])->save();
            }
        });
    }

    protected function assertMember(Organization $organization, User $member): void
    {
        if (!$member->canAccessOrganization($organization->id)) {
            throw ApiException::notFound('User is not a member of this organization');
        }
    }

    /**
     * An organization always keeps at least one owner.
     */
    protected function assertNotLastOwner(Organization $organization, User $member): void
    {
        if (!$member->hasRole(self::OWNER_ROLE, $organization->id)) {
            return;
        }

        $owners = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.slug', self::OWNER_ROLE)
            ->where('user_roles.organization_id', $organization->id)
            ->whereNull('user_roles.project_id')
            ->distinct()
            ->count('user_roles.user_id');

        if ($owners <= 1) {
            throw ApiException::conflict('An organization must keep at least one owner');
        }
    }

    /**
     * organization_users.role: owner / member (the effective permissions come
     * from user_roles).
     */
    protected function membershipRole(?string $roleSlug): string
    {
        return $roleSlug === self::OWNER_ROLE ? 'owner' : 'member';
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $suffix = 2;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
