<?php

namespace App\Domain\Platform\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationSetting;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates a complete organization in one database transaction:
 * organization + settings + roles from templates (+ first Organization Administrator).
 *
 * The administrator is given only where the account owner is the one acting: self-service
 * sign-up (a new account) or a logged-in account creating another organization (it keeps its
 * password and profile). The platform console passes no administrator; the organization then
 * has no member until the invited address accepts (OrganizationInvitations::inviteFirstAdmin).
 */
final class OrganizationProvisioner
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $organization  code + profile fields
     * @param  array{name: string, email: string, password: string}|User|null  $admin  new account data, an existing tenant account acting for itself, or none
     * @return array{organization: Organization, admin: User|null}
     */
    public function provision(array $organization, array|User|null $admin, bool $adminMustChangePassword = true): array
    {
        if (! $this->tenancy->isUnrestricted()) {
            throw ApiException::forbidden();
        }
        if ($admin instanceof User && ! $admin->isTenantUser()) {
            throw ApiException::unprocessable('INVALID_ADMIN', 'Akun platform tidak dapat menjadi anggota organisasi.');
        }

        return DB::transaction(function () use ($organization, $admin, $adminMustChangePassword) {
            $org = new Organization(array_intersect_key($organization, array_flip(Organization::PROFILE_FIELDS)));
            $org->forceFill(['code' => strtoupper($organization['code']), 'status' => Organization::STATUS_ACTIVE])->save();

            $settings = new OrganizationSetting;
            $settings->forceFill(['organization_id' => $org->id])->save();

            $roles = $this->createRoles($org);

            if ($admin === null) {
                $this->audit->record('organization.created', $org, after: ['code' => $org->code, 'name' => $org->name], organizationId: $org->id);

                return ['organization' => $org, 'admin' => null];
            }

            $existing = $admin instanceof User;
            if ($existing) {
                $user = $admin;
            } else {
                $user = new User($admin);
                $user->forceFill([
                    'user_type' => User::TYPE_TENANT,
                    'status' => User::STATUS_ACTIVE,
                    'must_change_password' => $adminMustChangePassword,
                ])->save();
            }
            (new OrganizationMember)->forceFill([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'status' => User::STATUS_ACTIVE,
            ])->save();
            $user->roles()->attach($roles[RoleTemplates::ADMIN]->id, ['organization_id' => $org->id]);
            UserDataScope::grant($org->id, $user->id, 'organization');

            $this->audit->record('organization.created', $org, after: [
                'code' => $org->code,
                'name' => $org->name,
                'admin_user_id' => $user->id,
                'admin_email' => $user->email,
                'admin_existing_account' => $existing,
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
