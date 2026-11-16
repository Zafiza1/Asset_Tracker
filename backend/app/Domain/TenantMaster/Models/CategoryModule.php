<?php

namespace App\Domain\TenantMaster\Models;

use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An optional module (OrganizationModule::CATALOG) picked for an asset category in Menu Modul.
 * Assets of the category and of its subcategories use the module, while the organization has
 * the module switched on.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $category_id
 * @property string $module
 */
class CategoryModule extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['category_id', 'module'];

    /**
     * Switched-on modules with the categories picked for them.
     *
     * @return array<string, array<string, true>> module => set of category ids
     */
    public static function activeByModule(): array
    {
        $enabled = OrganizationModule::enabledKeys();
        $out = array_fill_keys($enabled, []);
        if ($enabled === []) {
            return $out;
        }
        foreach (self::query()->whereIn('module', $enabled)->get(['module', 'category_id']) as $row) {
            $out[$row->module][$row->category_id] = true;
        }

        return $out;
    }

    /**
     * Modules an asset of the category with this path ("/root/child/") uses, in catalog order.
     *
     * @param  array<string, array<string, true>>  $active  from activeByModule()
     * @return list<string>
     */
    public static function modulesForPath(string $path, array $active): array
    {
        $lineage = array_filter(explode('/', $path));

        return array_values(array_filter(array_keys(OrganizationModule::CATALOG), fn (string $key) => isset($active[$key])
            && array_filter($lineage, fn (string $id) => isset($active[$key][$id])) !== []));
    }
}
