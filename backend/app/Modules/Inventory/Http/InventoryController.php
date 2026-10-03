<?php

namespace App\Modules\Inventory\Http;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\InventoryPolicy;
use App\Modules\Inventory\InventoryService;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryCountItem;
use App\Modules\Inventory\Models\InventoryLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/v1/inventory — only routed while the module is enabled for the
 * project (`module:inventory` middleware, see routes.php).
 *
 * Abilities are project-level (stock has no owning row), so they are
 * checked against InventoryPolicy directly.
 */
class InventoryController extends Controller
{
    use ResolvesProject;

    public function __construct(protected InventoryService $inventory)
    {
    }

    // ---- Stock & minimum levels -------------------------------------------

    public function stock(Request $request): JsonResponse
    {
        $project = $this->authorizeFor($request, 'view');
        $data = $request->validate([
            'location_id' => ['nullable', 'integer'],
            'asset_type' => ['nullable', 'string', 'max:255'],
            'low' => ['nullable', 'boolean'],
        ]);

        $rows = $this->inventory->stock($project, $data['location_id'] ?? null, $data['asset_type'] ?? null);

        if ($request->boolean('low')) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['low']));
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function levels(Request $request): JsonResponse
    {
        $this->authorizeFor($request, 'view');

        $levels = InventoryLevel::query()->with('location:id,name')->orderBy('location_id')->orderBy('asset_type')->get();

        return response()->json(['success' => true, 'data' => $levels->map(fn (InventoryLevel $level) => $this->levelData($level))]);
    }

    /** Create or replace the minimum for a location (and asset type). */
    public function setLevel(Request $request): JsonResponse
    {
        $project = $this->authorizeFor($request, 'adjust');
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'asset_type' => ['nullable', 'string', 'max:255'],
            'min_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $level = $this->inventory->setLevel($project, $data['location_id'], $data['asset_type'] ?? null, $data['min_quantity'], $request->user());

        return response()->json(['success' => true, 'data' => $this->levelData($level->load('location:id,name')), 'message' => 'Minimum level saved']);
    }

    public function deleteLevel(Request $request, InventoryLevel $level): JsonResponse
    {
        $project = $this->authorizeFor($request, 'adjust');
        $this->inventory->deleteLevel($level, $project, $request->user());

        return response()->json(['success' => true, 'data' => null, 'message' => 'Minimum level removed']);
    }

    // ---- Stock counts -------------------------------------------------------

    public function counts(Request $request): JsonResponse
    {
        $this->authorizeFor($request, 'view');
        $request->validate(['status' => ['nullable', Rule::in(InventoryCount::STATUSES)], 'location_id' => ['nullable', 'integer']]);

        $counts = InventoryCount::query()
            ->with('location:id,name')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('location_id'), fn ($q, $locationId) => $q->where('location_id', $locationId))
            ->latest('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => collect($counts->items())->map(fn (InventoryCount $count) => $this->countData($count)),
            'meta' => [
                'current_page' => $counts->currentPage(),
                'per_page' => $counts->perPage(),
                'total' => $counts->total(),
                'last_page' => $counts->lastPage(),
            ],
        ]);
    }

    public function showCount(Request $request, InventoryCount $count): JsonResponse
    {
        $this->authorizeFor($request, 'view');

        return $this->respondCount($count, withItems: true);
    }

    public function openCount(Request $request): JsonResponse
    {
        $project = $this->authorizeFor($request, 'adjust');
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $count = $this->inventory->openCount($project, $data['location_id'], $data['notes'] ?? null, $request->user());

        return $this->respondCount($count, 'Stock count opened', 201, withItems: true);
    }

    public function scan(Request $request, InventoryCount $count): JsonResponse
    {
        $this->authorizeFor($request, 'adjust');
        $data = $request->validate([
            'asset_ids' => ['required_without:serial_numbers', 'array', 'max:1000'],
            'asset_ids.*' => ['string', 'max:100'],
            'serial_numbers' => ['required_without:asset_ids', 'array', 'max:1000'],
            'serial_numbers.*' => ['string', 'max:255'],
        ]);

        $result = $this->inventory->scan($count, $data['asset_ids'] ?? [], $data['serial_numbers'] ?? [], $request->user());

        return response()->json([
            'success' => true,
            'data' => array_merge($result, ['summary' => $this->inventory->summary($count)]),
            'message' => "{$result['scanned']} assets scanned",
        ]);
    }

    public function complete(Request $request, InventoryCount $count): JsonResponse
    {
        $this->authorizeFor($request, 'adjust');
        $data = $request->validate(['reconcile' => ['sometimes', 'boolean']]);

        $count = $this->inventory->complete($count, (bool) ($data['reconcile'] ?? false), $request->user());

        return $this->respondCount($count, 'Stock count completed', withItems: true);
    }

    public function cancelCount(Request $request, InventoryCount $count): JsonResponse
    {
        $this->authorizeFor($request, 'adjust');

        return $this->respondCount($this->inventory->cancel($count, $request->user()), 'Stock count cancelled');
    }

    // ---- Helpers ------------------------------------------------------------

    protected function authorizeFor(Request $request, string $ability): \App\Models\Project
    {
        $project = $this->resolveProject();

        abort_unless(app(InventoryPolicy::class)->{$ability}($request->user(), $project), 403, 'This action is unauthorized.');

        return $project;
    }

    protected function levelData(InventoryLevel $level): array
    {
        return [
            'id' => $level->id,
            'location_id' => $level->location_id,
            'location_name' => $level->location?->name,
            'asset_type' => $level->asset_type,
            'min_quantity' => $level->min_quantity,
        ];
    }

    protected function countData(InventoryCount $count, bool $withItems = false): array
    {
        $data = [
            'id' => $count->id,
            'location_id' => $count->location_id,
            'location_name' => $count->location?->name,
            'status' => $count->status,
            'summary' => $this->inventory->summary($count),
            'notes' => $count->notes,
            'created_at' => $count->created_at?->toIso8601String(),
            'completed_at' => $count->completed_at?->toIso8601String(),
        ];

        if ($withItems) {
            $data['items'] = $count->items()
                ->with('asset:id,system_id,serial_number,name,asset_type', 'recordedLocation:id,name')
                ->get()
                ->map(fn (InventoryCountItem $item) => [
                    'system_id' => $item->asset?->system_id,
                    'serial_number' => $item->asset?->serial_number,
                    'name' => $item->asset?->name,
                    'asset_type' => $item->asset?->asset_type,
                    'outcome' => $item->outcome(),
                    'scanned_at' => $item->scanned_at?->toIso8601String(),
                    'recorded_location' => $item->recordedLocation?->name,
                    'reconciled' => $item->reconciled,
                ])
                ->sortBy('outcome')
                ->values();
        }

        return $data;
    }

    protected function respondCount(InventoryCount $count, string $message = 'Success', int $status = 200, bool $withItems = false): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->countData($count->fresh('location:id,name'), $withItems),
            'message' => $message,
        ], $status);
    }
}
