<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationSetting;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates a complete, usable organization in one database transaction:
 * organization + settings + roles from templates + first Organization Administrator.
 */
final class OrganizationProvisioner
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $organization  code + profile fields
     * @param  array{name: string, email: string, password: string}  $admin
     * @return array{organization: Organization, admin: User}
     */
    public function provision(array $organization, array $admin, bool $adminMustChangePassword = true): array
    {
        if (! $this->tenancy->isUnrestricted()) {
            throw ApiException::forbidden();
        }

        return DB::transaction(function () use ($organization, $admin, $adminMustChangePassword) {
            $org = new Organization(array_intersect_key($organization, array_flip(Organization::PROFILE_FIELDS)));
            $org->forceFill(['code' => strtoupper($organization['code']), 'status' => Organization::STATUS_ACTIVE])->save();

            $settings = new OrganizationSetting;
            $settings->forceFill(['organization_id' => $org->id])->save();

            $roles = $this->createRoles($org);

            $user = new User($admin);
            $user->forceFill([
                'user_type' => User::TYPE_TENANT,
                'organization_id' => $org->id,
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => $adminMustChangePassword,
            ])->save();
            $user->roles()->attach($roles[RoleTemplates::ORG_ADMIN]->id, ['organization_id' => $org->id]);
            UserDataScope::grant($org->id, $user->id, 'organization');

            $this->audit->record('organization.created', $org, after: [
                'code' => $org->code,
                'name' => $org->name,
                'admin_user_id' => $user->id,
                'admin_email' => $user->email,
            ], organizationId: $org->id);

            return ['organization' => $org, 'admin' => $user];
        });
    }

    /** @return array<string, Role> */
    private function createRoles(Organization $org): array
    {
        $permissionIds = Permission::query()->where('scope', PermissionCatalog::SCOPE_TENANT)->pluck('id', 'code');
        $roles = [];

        foreach (RoleTemplates::tenant() as $code => $template) {
            $role = new Role(['code' => $code, 'name' => $template['name'], 'description' => $template['description']]);
            $role->forceFill([
                'organization_id' => $org->id,
                'template_code' => $code,
                'is_locked' => $template['locked'],
            ])->save();

            $attach = [];
            foreach ($template['permissions'] as $permission) {
                $attach[$permissionIds[$permission] ?? throw new LogicException("Permission {$permission} not synced")] = ['organization_id' => $org->id];
            }
            $role->permissions()->attach($attach);
            $roles[$code] = $role;
        }

        return $roles;
    }
}
