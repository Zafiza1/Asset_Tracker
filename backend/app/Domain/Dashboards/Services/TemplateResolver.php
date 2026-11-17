<?php

namespace App\Domain\Dashboards\Services;

use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\TenantMaster\Models\TenantAssetCategory;

/**
 * Picks the template a system dashboard (Overview, category dashboard) is built from. Only
 * active default templates are picked automatically; other templates are applied explicitly.
 *
 * Category dashboards, by category type key (never a tenant category id), most specific first:
 *  1. the category's own code (e.g. AST_IT for the standard categories), then the codes of its
 *     ancestors (nearest first), then the code of its generic category;
 *  for each key the organization's template wins over the system one;
 *  2. the generic category template (category_type_key NULL): organization, then system.
 */
final class TemplateResolver
{
    public function forOverview(): ?DashboardTemplate
    {
        return $this->rank(
            DashboardTemplate::query()->where('dashboard_type', DashboardType::OVERVIEW)->where('is_active', true)->where('is_default', true)->get(),
            [],
        );
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
