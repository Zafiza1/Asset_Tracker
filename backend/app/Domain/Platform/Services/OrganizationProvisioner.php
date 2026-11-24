<?php

namespace App\Domain\Platform\Services;

use App\Domain\AssetTransactions\SystemTransactionTypes;
use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Permission;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Models\UserDataScope;
use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\Dashboards\Models\TemplateWidget;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\OrganizationSetting;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Services\TenantCategoryService;
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
    /** Built-in role => system asset board template it is mapped to (F-06 §4.1, D-21). */
    private const ASSET_BOARD_TEMPLATES = [
        RoleTemplates::SUPER_ADMIN => 'asset_board_super_admin',
        RoleTemplates::SUPERVISOR => 'asset_board_supervisor',
        RoleTemplates::ADMIN => 'asset_board_admin',
    ];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
        private readonly TenantCategoryService $categories,
    ) {}

    /**
     * @param  array<string, mixed>  $organization  code + profile fields
     * @param  array{name: string, email: string, password: string}|User|null  $admin  new account data, an existing tenant account acting for itself, or none
     * @return array{organization: Organization, admin: User|null}
     */
    public function provision(array $organization, array|User|null $admin, bool $adminMustChangePassword = true, bool $standardCategories = true): array
    {
        if (! $this->tenancy->isUnrestricted()) {
            throw ApiException::forbidden();
        }
        if ($admin instanceof User && ! $admin->isTenantUser()) {
            throw ApiException::unprocessable('INVALID_ADMIN', 'Akun platform tidak dapat menjadi anggota organisasi.');
        }

        return DB::transaction(function () use ($organization, $admin, $adminMustChangePassword, $standardCategories) {
            $org = new Organization(array_intersect_key($organization, array_flip(Organization::PROFILE_FIELDS)));
            $org->forceFill(['code' => strtoupper($organization['code']), 'status' => Organization::STATUS_ACTIVE])->save();

            $settings = new OrganizationSetting;
            $settings->forceFill(['organization_id' => $org->id])->save();

            $roles = $this->createRoles($org);
            $this->mapAssetBoardTemplates($org, $roles);
            SystemTransactionTypes::install($org->id);
            if ($standardCategories) {
                $this->categories->installStandard($org->id);
            }

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
            // First user who registers organization becomes SUPER_ADMIN
            $user->roles()->attach($roles[RoleTemplates::SUPER_ADMIN]->id, ['organization_id' => $org->id]);
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

    /**
     * F-06 §4.1 (D-21): map the three built-in asset board templates to the three built-in roles.
     * Creates organization templates as copies of the system templates. The role mapping, not
     * is_default, decides which template a member gets (TemplateResolver::forRole), so the copies
     * are not marked default: at most one default per owner and dashboard type is allowed.
     *
     * @param  array<string, Role>  $roles
     */
    private function mapAssetBoardTemplates(Organization $org, array $roles): void
    {
        $systemTemplates = DashboardTemplate::query()->whereNull('organization_id')
            ->where('dashboard_type', DashboardType::ASSET_BOARD)
            ->whereIn('template_key', array_values(self::ASSET_BOARD_TEMPLATES))
            ->with('widgets')
            ->get()->keyBy('template_key');

        foreach (self::ASSET_BOARD_TEMPLATES as $roleCode => $templateKey) {
            $systemTemplate = $systemTemplates[$templateKey] ?? null;
            $role = $roles[$roleCode] ?? null;
            if ($systemTemplate === null || $role === null) {
                continue;
            }

            // Columns outside $fillable (organization, source, scope, version, role) need forceFill.
            $orgTemplate = new DashboardTemplate;
            $orgTemplate->forceFill([
                'organization_id' => $org->id,
                'source_template_id' => $systemTemplate->id,
                'template_key' => $templateKey,
                'name' => $systemTemplate->name,
                'description' => $systemTemplate->description,
                'dashboard_type' => DashboardType::ASSET_BOARD,
                'category_type_key' => null,
                'scope' => DashboardTemplate::SCOPE_ORGANIZATION,
                'version' => $systemTemplate->version,
                'is_default' => false,
                'is_active' => true,
                'role_id' => $role->id,
            ])->save();

            foreach ($systemTemplate->widgets as $templateWidget) {
                (new TemplateWidget)->forceFill([
                    'organization_id' => $org->id,
                    'template_id' => $orgTemplate->id,
                    'widget_catalog_id' => $templateWidget->widget_catalog_id,
                    'title' => $templateWidget->title,
                    'configuration' => $templateWidget->configuration,
                    'layout' => $templateWidget->layout,
                    'sort_order' => $templateWidget->sort_order,
                ])->save();
            }
        }
    }
}
