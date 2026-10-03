<?php

namespace App\Modules\Inventory;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Modules\Concerns\ChecksProjectLocations;
use App\Modules\Inventory\Events\InventoryEvent;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryCountItem;
use App\Modules\Inventory\Models\InventoryLevel;
use App\Modules\ModuleSettings;
use App\Services\AuditService;
use App\Services\MovementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inventory module business logic.
 *
 * Stock is never stored: it is the number of (non-deleted) assets whose
 * current location (Core asset_locations) is the location. The module adds
 * minimum levels, low-stock alerts and stock counts. Its only Core write is
 * reconciling a count, which goes through MovementService.
 */
class InventoryService
{
    use ChecksProjectLocations;

    public const MODULE_SLUG = 'inventory';

    public const MOVEMENT_SOURCE = 'inventory';

    public function __construct(
        protected MovementService $movements,
        protected ModuleSettings $settings,
        protected AuditService $audit,
    ) {
    }

    // ---- Stock ------------------------------------------------------------

    /**
     * Stock per location and asset type, with each group's minimum and a low
     * flag. Includes groups with a minimum but no assets (quantity 0), and a
     * row per location-wide minimum (all_types: every asset at the location).
     * The low_stock_threshold setting applies to per-type groups without a
     * minimum of their own.
     *
     * @return array<int, array{location_id: int, location_name: ?string, asset_type: ?string, all_types: bool, quantity: int, min_quantity: ?int, low: bool}>
     */
    public function stock(Project $project, ?int $locationId = null, ?string $assetType = null): array
    {
        $counts = $this->stockQuery($project->id)
            ->when($locationId, fn ($q) => $q->where('asset_locations.location_id', $locationId))
            ->when($assetType, fn ($q) => $q->where('assets.asset_type', $assetType))
            ->groupBy('asset_locations.location_id', 'assets.asset_type')
            ->select('asset_locations.location_id', 'assets.asset_type', DB::raw('COUNT(*) AS quantity'))
            ->get();

        $levels = InventoryLevel::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->get()
            ->keyBy(fn (InventoryLevel $level) => $this->groupKey($level->location_id, $level->asset_type, $level->asset_type === null));
        $threshold = (int) $this->settings->get($project, self::MODULE_SLUG, 'low_stock_threshold', 0);

        $rows = [];
        foreach ($counts as $count) {
            $rows[$this->groupKey($count->location_id, $count->asset_type, false)] = [
                'location_id' => (int) $count->location_id,
                'asset_type' => $count->asset_type,
                'all_types' => false,
                'quantity' => (int) $count->quantity,
            ];
        }

        foreach ($levels as $key => $level) {
            if ($assetType && $level->asset_type !== $assetType) {
                continue;
            }

            $rows[$key] ??= [
                'location_id' => $level->location_id,
                'asset_type' => $level->asset_type,
                'all_types' => $level->asset_type === null,
                'quantity' => $level->asset_type === null
                    ? $counts->where('location_id', $level->location_id)->sum('quantity')
                    : 0,
            ];
        }

        $names = Location::withoutGlobalScopes()->whereIn('id', array_column($rows, 'location_id'))->pluck('name', 'id');

        return collect($rows)
            ->map(function (array $row, string $key) use ($levels, $threshold, $names) {
                $min = $levels[$key]->min_quantity ?? (!$row['all_types'] && $threshold > 0 ? $threshold : null);

                return [
                    'location_id' => $row['location_id'],
                    'location_name' => $names[$row['location_id']] ?? null,
                    'asset_type' => $row['asset_type'],
                    'all_types' => $row['all_types'],
                    'quantity' => (int) $row['quantity'],
                    'min_quantity' => $min,
                    'low' => $min !== null && $row['quantity'] < $min,
                ];
            })
            ->sortBy([['location_name', 'asc'], ['asset_type', 'asc']])
            ->values()
            ->all();
    }

    /** Assets currently at a location (of a type, or of any type). */
    public function quantityAt(int $projectId, int $locationId, ?string $assetType): int
    {
        return $this->stockQuery($projectId)
            ->where('asset_locations.location_id', $locationId)
            ->when($assetType !== null, fn ($q) => $q->where('assets.asset_type', $assetType))
            ->count();
    }

    // ---- Minimum levels -----------------------------------------------------

    public function setLevel(Project $project, int $locationId, ?string $assetType, int $minQuantity, ?User $user = null): InventoryLevel
    {
        $this->assertLocationInProject($project, $locationId);

        $level = InventoryLevel::withoutGlobalScopes()->updateOrCreate(
            ['project_id' => $project->id, 'location_id' => $locationId, 'asset_type' => $assetType],
            ['organization_id' => $project->organization_id, 'min_quantity' => $minQuantity],
        );
        $this->log('inventory.level.set', 'inventory_level', $level->id, $project, $user, ['min_quantity' => $minQuantity]);

        return $level;
    }

    public function deleteLevel(InventoryLevel $level, Project $project, ?User $user = null): void
    {
        $level->delete();
        $this->log('inventory.level.deleted', 'inventory_level', $level->id, $project, $user);
    }

    /**
     * After an asset leaves a location: announce inventory.low_stock once,
     * when the location's stock first drops below a minimum (not on every
     * further move while it stays low).
     */
    public function checkLowStockAfterDeparture(Project $project, Asset $asset, int $fromLocationId): void
    {
        $threshold = (int) $this->settings->get($project, self::MODULE_SLUG, 'low_stock_threshold', 0);
        $levels = InventoryLevel::withoutGlobalScopes()
            ->where('location_id', $fromLocationId)
            ->where(fn ($q) => $q->whereNull('asset_type')->orWhere('asset_type', $asset->asset_type))
            ->get()
            ->keyBy(fn (InventoryLevel $level) => $level->asset_type ?? '*');

        $rules = [];
        if ($asset->asset_type !== null && ($min = $levels[$asset->asset_type]->min_quantity ?? ($threshold ?: null))) {
            $rules[] = [$asset->asset_type, $min];
        }
        if (isset($levels['*'])) {
            $rules[] = [null, $levels['*']->min_quantity];
        }

        foreach ($rules as [$assetType, $min]) {
            $quantity = $this->quantityAt($project->id, $fromLocationId, $assetType);

            if ($quantity < $min && $quantity + 1 >= $min) {
                $location = Location::withoutGlobalScopes()->find($fromLocationId);
                InventoryEvent::dispatch('inventory.low_stock', $project->organization_id, $project->id, [
                    'location_id' => $fromLocationId,
                    'location_name' => $location?->name,
                    'asset_type' => $assetType,
                    'quantity' => $quantity,
                    'min_quantity' => $min,
                    'last_asset' => ['system_id' => $asset->system_id, 'serial_number' => $asset->serial_number],
                ]);
            }
        }
    }

    // ---- Stock counts -------------------------------------------------------

    /** Opens a count; the assets recorded at the location become "expected". */
    public function openCount(Project $project, int $locationId, ?string $notes, ?User $user = null): InventoryCount
    {
        $this->assertLocationInProject($project, $locationId);

        return DB::transaction(function () use ($project, $locationId, $notes, $user) {
            $alreadyOpen = InventoryCount::withoutGlobalScopes()
                ->where('location_id', $locationId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                throw ApiException::conflict('This location already has an open stock count');
            }

            $count = InventoryCount::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'location_id' => $locationId,
                'status' => 'open',
                'notes' => $notes,
                'created_by' => $user?->id,
            ]);

            $expected = $this->stockQuery($project->id)
                ->where('asset_locations.location_id', $locationId)
                ->pluck('assets.id');
            $now = now();
            $count->items()->insert($expected->map(fn ($assetId) => [
                'inventory_count_id' => $count->id,
                'asset_id' => $assetId,
                'expected' => true,
                'scanned' => false,
                'reconciled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            $this->log('inventory.count.opened', 'inventory_count', $count->id, $project, $user, ['expected' => $expected->count()]);

            return $count;
        });
    }

    /**
     * Record scanned assets (by system ID or serial number). Scanning twice
     * is harmless. Identifiers that match no asset of the project are
     * returned, not rejected — a reader may pick up foreign tags.
     *
     * @return array{scanned: int, unknown: array<int, string>}
     */
    public function scan(InventoryCount $count, array $systemIds, array $serialNumbers, ?User $user = null): array
    {
        $this->assertOpen($count);

        $assets = Asset::withoutGlobalScopes()
            ->where('project_id', $count->project_id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn('system_id', $systemIds)->orWhereIn('serial_number', $serialNumbers))
            ->get();

        $unknown = array_values(array_merge(
            array_diff($systemIds, $assets->pluck('system_id')->all()),
            array_diff($serialNumbers, $assets->pluck('serial_number')->all()),
        ));

        DB::transaction(function () use ($count, $assets) {
            $now = now();
            $current = AssetLocation::withoutGlobalScopes()->whereIn('asset_id', $assets->pluck('id'))->pluck('location_id', 'asset_id');

            foreach ($assets as $asset) {
                $item = $count->items()->firstOrNew(['asset_id' => $asset->id]);

                if (!$item->exists) {
                    $item->expected = false;
                    $item->recorded_location_id = $current[$asset->id] ?? null;
                }

                if (!$item->scanned) {
                    $item->scanned = true;
                    $item->scanned_at = $now;
                }

                $item->save();
            }
        });

        return ['scanned' => $assets->count(), 'unknown' => $unknown];
    }

    /**
     * Close the count. Expected assets not scanned are reported missing. With
     * $reconcile, assets found here but recorded elsewhere are moved here
     * (through MovementService) so Core matches the shelf.
     */
    public function complete(InventoryCount $count, bool $reconcile, ?User $user = null): InventoryCount
    {
        $this->assertOpen($count);

        return DB::transaction(function () use ($count, $reconcile, $user) {
            $items = $count->items()->with('asset')->get();
            $unexpected = $items->filter(fn (InventoryCountItem $item) => $item->outcome() === 'unexpected');

            if ($reconcile) {
                foreach ($unexpected as $item) {
                    $this->movements->recordMovement($item->asset, [
                        'to_location_id' => $count->location_id,
                        'source' => self::MOVEMENT_SOURCE,
                        'recorded_by' => $user?->id,
                        'metadata' => ['inventory_count_id' => $count->id, 'step' => 'reconciled'],
                    ]);
                    $item->update(['reconciled' => true]);
                }
            }

            $summary = $this->summarize($items, $reconcile ? $unexpected->count() : 0);
            $count->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $user?->id,
                'summary' => $summary,
            ]);

            InventoryEvent::dispatch('inventory.count.completed', $count->organization_id, $count->project_id, [
                'inventory_count_id' => $count->id,
                'location_id' => $count->location_id,
                'summary' => $summary,
                'missing' => $this->systemIds($items, 'missing'),
                'unexpected' => $this->systemIds($items, 'unexpected'),
            ]);
            $this->log('inventory.count.completed', 'inventory_count', $count->id, $count->project, $user, $summary);

            return $count;
        });
    }

    public function cancel(InventoryCount $count, ?User $user = null): InventoryCount
    {
        $this->assertOpen($count);
        $count->update(['status' => 'cancelled']);
        $this->log('inventory.count.cancelled', 'inventory_count', $count->id, $count->project, $user);

        return $count;
    }

    /** Live tallies of an open count, or the stored summary of a closed one. */
    public function summary(InventoryCount $count): array
    {
        return $count->summary ?? $this->summarize($count->items()->get(), 0);
    }

    protected function summarize(Collection $items, int $reconciled): array
    {
        $outcomes = $items->map(fn (InventoryCountItem $item) => $item->outcome())->countBy();

        return [
            'expected' => $items->where('expected', true)->count(),
            'found' => $outcomes['found'] ?? 0,
            'missing' => $outcomes['missing'] ?? 0,
            'unexpected' => $outcomes['unexpected'] ?? 0,
            'reconciled' => $reconciled,
        ];
    }

    protected function systemIds(Collection $items, string $outcome): array
    {
        return $items->filter(fn (InventoryCountItem $item) => $item->outcome() === $outcome)
            ->map(fn (InventoryCountItem $item) => $item->asset?->system_id)
            ->values()
            ->all();
    }

    /** Non-deleted assets with their current location, for one project. */
    protected function stockQuery(int $projectId)
    {
        return DB::table('asset_locations')
            ->join('assets', 'assets.id', '=', 'asset_locations.asset_id')
            ->where('asset_locations.project_id', $projectId)
            ->whereNotNull('asset_locations.location_id')
            ->whereNull('assets.deleted_at');
    }

    /** $allTypes groups every asset at the location; otherwise one type (null = untyped). */
    protected function groupKey(int $locationId, ?string $assetType, bool $allTypes): string
    {
        return $locationId . '|' . ($allTypes ? '*' : 'type:' . ($assetType ?? ''));
    }

    protected function assertOpen(InventoryCount $count): void
    {
        if ($count->status !== 'open') {
            throw ApiException::conflict("This stock count is {$count->status}");
        }
    }

    protected function log(string $action, string $resourceType, int $resourceId, Project $project, ?User $user, array $extra = []): void
    {
        $this->audit->logActivity($action, $resourceType, $resourceId, $extra ?: null, $user?->id, $project->organization_id, $project->id);
    }
}
