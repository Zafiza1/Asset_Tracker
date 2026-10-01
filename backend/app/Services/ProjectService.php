<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Scopes\TenantScope;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Project lifecycle and membership — Control Plane, Section 4.
 *
 * Project members must belong to the project's organization. Their project-
 * level roles (user_roles with project_id set) add to any organization-level
 * role they hold there.
 */
class ProjectService
{
    public const ADMIN_ROLE = 'project-admin';

    public function __construct(
        protected RoleAssignment $roles,
        protected TemplateService $templateService
    ) {
    }

    /**
     * An organization member who creates a project becomes its Project Admin.
     * If a template is provided, its modules are applied via Template Engine (Phase 7).
     */
    public function create(Organization $organization, User $creator, array $data): Project
    {
        return DB::transaction(function () use ($organization, $creator, $data) {
            $project = Project::create(array_merge($data, [
                'organization_id' => $organization->id,
                'slug' => $data['slug'] ?? $this->uniqueSlug($organization, $data['name']),
                'status' => 'active',
            ]));

            if ($creator->canAccessOrganization($organization->id)) {
                $project->users()->attach($creator->id, [
                    'role' => $this->membershipRole(self::ADMIN_ROLE),
                    'joined_at' => now(),
                ]);
                $creator->assignRole(self::ADMIN_ROLE, $organization->id, $project->id);
            }

            // Apply template if provided
            if (isset($data['template_id']) && $data['template_id']) {
                $template = Template::find($data['template_id']);
                if ($template) {
                    try {
                        $this->templateService->applyToProject($template, $project);
                    } catch (\Exception $e) {
                        Log::error('Failed to apply template to project', [
                            'template_id' => $template->id,
                            'project_id' => $project->id,
                            'error' => $e->getMessage(),
                        ]);
                        // Don't fail project creation if template application fails
                        // The project is created, just without template modules
                    }
                }
            }

            return $project;
        });
    }

    public function update(Project $project, array $data): Project
    {
        $project->update($data);

        return $project->fresh();
    }

    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project) {
            User::where('default_project_id', $project->id)->update(['default_project_id' => null]);
            $project->delete();
        });
    }

    public function addMember(Project $project, User $actor, User $member, ?string $roleSlug): void
    {
        if (!$member->canAccessOrganization($project->organization_id)) {
            throw ApiException::invalid('Validation failed', [
                'email' => ['User must be a member of the organization before joining its projects'],
            ]);
        }

        if ($this->isMember($project, $member)) {
            throw ApiException::conflict('User is already a member of this project');
        }

        if ($roleSlug) {
            $this->roles->assertAssignable($actor, $roleSlug, $project->organization_id, $project->id);
        }

        DB::transaction(function () use ($project, $member, $roleSlug) {
            $project->users()->attach($member->id, [
                'role' => $this->membershipRole($roleSlug),
                'joined_at' => now(),
            ]);

            if ($roleSlug) {
                $member->assignRole($roleSlug, $project->organization_id, $project->id);
            }
        });
    }

    public function changeMemberRole(Project $project, User $actor, User $member, ?string $roleSlug): void
    {
        $this->assertMember($project, $member);

        if ($actor->is($member)) {
            throw ApiException::conflict('You cannot change your own role');
        }

        $this->roles->assertCanManage($actor, $member, $project->organization_id, $project->id);

        if ($roleSlug) {
            $this->roles->assertAssignable($actor, $roleSlug, $project->organization_id, $project->id);
        }

        DB::transaction(function () use ($project, $member, $roleSlug) {
            $member->syncRoles($roleSlug ? [$roleSlug] : [], $project->organization_id, $project->id);
            $project->users()->updateExistingPivot($member->id, [
                'role' => $this->membershipRole($roleSlug),
            ]);
        });
    }

    /**
     * Members may remove themselves (leave). Organization-level roles are
     * untouched: those are managed on the organization.
     */
    public function removeMember(Project $project, User $actor, User $member): void
    {
        $this->assertMember($project, $member);

        if (!$actor->is($member)) {
            $this->roles->assertCanManage($actor, $member, $project->organization_id, $project->id);
        }

        DB::transaction(function () use ($project, $member) {
            $project->users()->detach($member->id);

            DB::table('user_roles')
                ->where('user_id', $member->id)
                ->where('project_id', $project->id)
                ->delete();
            DB::table('user_permissions')
                ->where('user_id', $member->id)
                ->where('project_id', $project->id)
                ->delete();

            if ($member->default_project_id === $project->id) {
                $member->forceFill(['default_project_id' => null])->save();
            }
        });
    }

    public function isMember(Project $project, User $member): bool
    {
        return $project->users()->where('user_id', $member->id)->exists();
    }

    protected function assertMember(Project $project, User $member): void
    {
        if (!$this->isMember($project, $member)) {
            throw ApiException::notFound('User is not a member of this project');
        }
    }

    /**
     * project_users.role mirrors the granted role (admin for Project Admin).
     */
    protected function membershipRole(?string $roleSlug): string
    {
        return match ($roleSlug) {
            null => 'member',
            self::ADMIN_ROLE => 'admin',
            default => $roleSlug,
        };
    }

    protected function uniqueSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while (Project::withoutGlobalScope(TenantScope::class)
            ->withTrashed()
            ->where('organization_id', $organization->id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
