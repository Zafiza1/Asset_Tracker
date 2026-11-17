<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\Models\Asset;
use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\TenantMaster\Models\CategoryModule;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The organization's optional modules (OrganizationModule::CATALOG), switched on and off, and
 * the asset categories whose assets use each module (checklist in Menu Modul).
 */
class OrganizationModuleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->modules()]);
    }

    public function update(Request $request, string $module): JsonResponse
    {
        $this->assertModule($module);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        DB::transaction(function () use ($module, $enabled) {
            $row = OrganizationModule::query()->where('module', $module)->lockForUpdate()->first();
            if ($enabled === ($row !== null)) {
                return;
            }
            $enabled ? OrganizationModule::query()->create(['module' => $module]) : $row->delete();
            $this->audit->record($enabled ? 'module.enabled' : 'module.disabled', metadata: ['module' => $module]);
        });

        return response()->json(['data' => $this->modules()]);
    }

    /**
     * Active categories as a tree (parents before their children, siblings by name), each with
     * whether it has the module itself (`assigned`) or through a parent category (`inherited`),
     * and its active assets, subcategories included (`asset_count`).
     */
    public function categories(string $module): JsonResponse
    {
        $this->assertModule($module);
        $categories = TenantAssetCategory::query()->where('status', MasterStatus::ACTIVE)
            ->orderBy('name')->get(['id', 'code', 'name', 'parent_id', 'path']);
        $assigned = array_flip(CategoryModule::query()->where('module', $module)->pluck('category_id')->all());
        $direct = Asset::query()->whereNull('archived_at')->groupBy('category_id')
            ->selectRaw('category_id, count(*) as n')->pluck('n', 'category_id')->all();

        $children = $categories->groupBy(fn ($c) => $c->parent_id ?? '');
        $ids = $categories->keyBy('id');
        $rows = [];
        $walk = function (string $parent, int $depth, bool $inherited) use (&$walk, &$rows, $children, $assigned, $direct): int {
            $total = 0;
            foreach ($children->get($parent, []) as $c) {
                $index = count($rows);
                $rows[] = null;
                $own = isset($assigned[$c->id]);
                $count = (int) ($direct[$c->id] ?? 0) + $walk($c->id, $depth + 1, $inherited || $own);
                $rows[$index] = [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'parent_id' => $c->parent_id,
                    'depth' => $depth,
                    'asset_count' => $count,
                    'assigned' => $own,
                    'inherited' => $inherited,
                ];
                $total += $count;
            }

            return $total;
        };
        $walk('', 0, false);
        // Categories whose parent is not active (archived) still show, as top-level rows.
        $orphanParents = $categories->pluck('parent_id')->filter(fn ($id) => $id !== null && ! $ids->has($id))->unique();
        foreach ($orphanParents as $parent) {
            $walk($parent, 0, false);
        }

        $using = collect($rows)->filter(fn ($r) => $r['assigned'] && ! $r['inherited'])->sum('asset_count');

        return response()->json(['data' => $rows, 'assigned_assets' => $using]);
    }

    /** Checks (assigned: true) or unchecks the module on the given categories. */
    public function updateCategories(Request $request, string $module): JsonResponse
    {
        $this->assertModule($module);
        $data = $request->validate([
            'category_ids' => ['required', 'array', 'min:1', 'max:500'],
            'category_ids.*' => ['uuid', 'distinct'],
            'assigned' => ['required', 'boolean'],
        ]);
        $ids = TenantAssetCategory::query()->whereIn('id', $data['category_ids'])->pluck('id')->all();
        if (count($ids) !== count($data['category_ids'])) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Sebagian kategori tidak ditemukan.');
        }

        $changed = DB::transaction(function () use ($module, $ids, $data) {
            $current = CategoryModule::query()->where('module', $module)->whereIn('category_id', $ids)->pluck('category_id')->all();
            if ($data['assigned']) {
                $changed = array_values(array_diff($ids, $current));
                foreach ($changed as $id) {
                    CategoryModule::query()->create(['category_id' => $id, 'module' => $module]);
                }
            } else {
                $changed = $current;
                CategoryModule::query()->where('module', $module)->whereIn('category_id', $changed)->delete();
            }
            if ($changed !== []) {
                $this->audit->record($data['assigned'] ? 'module.categories_added' : 'module.categories_removed',
                    metadata: ['module' => $module, 'category_ids' => $changed, 'count' => count($changed)]);
            }

            return $changed;
        });

        return response()->json(['data' => ['changed' => count($changed)]]);
    }

    private function assertModule(string $module): void
    {
        if (! isset(OrganizationModule::CATALOG[$module])) {
            throw new ApiException('MODULE_NOT_FOUND', 'Modul tidak ditemukan.', 404);
        }
    }

    /** @return list<array{key: string, name: string, description: string, enabled: bool}> */
    private function modules(): array
    {
        $enabled = OrganizationModule::enabledKeys();

        return array_map(
            fn (string $key) => ['key' => $key, ...OrganizationModule::CATALOG[$key], 'enabled' => in_array($key, $enabled, true)],
            array_keys(OrganizationModule::CATALOG),
        );
    }
}
