<?php

namespace App\Domain\Dashboards\Services;

use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Support\Facades\DB;

/**
 * Picks the template a system dashboard (Overview, category dashboard, asset board) is built
 * from. Only active default templates are picked automatically for Overview and categories;
 * other templates are applied explicitly.
 *
 * Category dashboards, by category type key (never a tenant category id), most specific first:
 *  1. the category's own code (e.g. AST_IT for the standard categories), then the codes of its
 *     ancestors (nearest first), then the code of its generic category;
 *  for each key the organization's template wins over the system one;
 *  2. the generic category template (category_type_key NULL): organization, then system.
 *
 * Asset boards (F-06 §4.1, D-21): the organization template mapped to one of the member's
 * roles wins; a template without a role mapping is the fallback.
 */
final class TemplateResolver
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function forOverview(): ?DashboardTemplate
    {
        return $this->rank(
            DashboardTemplate::query()->where('dashboard_type', DashboardType::OVERVIEW)->where('is_active', true)->where('is_default', true)->get(),
            [],
        );
    }

    /**
     * The asset-board template for the member: the organization template mapped to one of
     * their roles wins; when several roles map to templates the most senior template role
     * decides (Super Admin > Supervisor > Admin > custom). A template with no role mapping
     * is the fallback for members whose roles map to nothing.
     */
    public function forRole(User $user): ?DashboardTemplate
    {
        $roleIds = DB::table('user_roles')
            ->where('organization_id', $this->tenancy->organizationId())
            ->where('user_id', $user->id)->pluck('role_id');
        if ($roleIds->isEmpty()) {
            return null;
        }
        $priority = [RoleTemplates::SUPER_ADMIN => 0, RoleTemplates::SUPERVISOR => 1, RoleTemplates::ADMIN => 2];
        $templates = DashboardTemplate::query()->where('dashboard_type', DashboardType::ASSET_BOARD)
            ->where('is_active', true)->whereIn('role_id', $roleIds)->get();
        $ranked = $templates->map(function (DashboardTemplate $t) use ($priority) {
            $role = Role::query()->find($t->role_id);

            return [$priority[$role?->template_code] ?? 3, $t];
        })->sortBy(fn ($pair) => $pair[0])->values();
        if ($ranked->isNotEmpty()) {
            return $ranked->first()[1];
        }

        return DashboardTemplate::query()->where('dashboard_type', DashboardType::ASSET_BOARD)
            ->where('is_active', true)->whereNull('role_id')->orderBy('created_at')->first();
    }

    public function forCategory(TenantAssetCategory $category): ?DashboardTemplate
    {
        $keys = $this->categoryTypeKeys($category);

        return $this->rank(
            DashboardTemplate::query()
                ->where('dashboard_type', DashboardType::CATEGORY)->where('is_active', true)->where('is_default', true)
                ->where(fn ($q) => $q->whereIn('category_type_key', $keys)->orWhereNull('category_type_key'))
                ->get(),
            $keys,
        );
    }

    /**
     * The category type keys of a category, most specific first.
     *
     * @return list<string>
     */
    public function categoryTypeKeys(TenantAssetCategory $category): array
    {
        $ancestorIds = array_reverse(array_values(array_filter(
            explode('/', (string) $category->path),
            fn (string $id) => $id !== '' && $id !== $category->id,
        )));
        $codes = $ancestorIds === [] ? collect() : TenantAssetCategory::query()->whereKey($ancestorIds)->pluck('code', 'id');

        $keys = [$category->code];
        foreach ($ancestorIds as $id) {
            if (isset($codes[$id])) {
                $keys[] = $codes[$id];
            }
        }
        $generic = GenericAssetCategory::query()->whereKey($category->generic_category_id)->value('code');
        if ($generic !== null) {
            $keys[] = $generic;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Best template: position of its key in $keys (NULL key last), then organization before system.
     *
     * @param  iterable<DashboardTemplate>  $templates
     * @param  list<string>  $keys
     */
    private function rank(iterable $templates, array $keys): ?DashboardTemplate
    {
        $positions = array_flip($keys);
        $best = null;
        $bestRank = null;
        foreach ($templates as $template) {
            $key = $template->category_type_key;
            $position = $key === null ? count($keys) : ($positions[$key] ?? null);
            if ($position === null) {
                continue;
            }
            $rank = [$position, $template->isSystem() ? 1 : 0];
            if ($bestRank === null || $rank < $bestRank) {
                [$best, $bestRank] = [$template, $rank];
            }
        }

        return $best;
    }
}
