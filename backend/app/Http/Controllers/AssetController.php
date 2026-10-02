<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\EventLog;
use App\Services\CustomFieldValueService;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    use ResolvesProject;

    public function index(Request $request)
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Asset::class, $project]);

        $query = Asset::query()->with('locationAssignment.location');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('serial_number', 'ilike', "%{$search}%")
                    ->orWhere('system_id', 'ilike', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->ofStatus($status);
        }

        if ($assetType = $request->query('asset_type')) {
            $query->ofType($assetType);
        }

        $sort = (string) $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (in_array($column, ['name', 'created_at', 'serial_number', 'status'], true)) {
            $query->orderBy($column, $direction);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $assets = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => AssetResource::collection($assets->items()),
            'meta' => [
                'current_page' => $assets->currentPage(),
                'per_page' => $assets->perPage(),
                'total' => $assets->total(),
                'last_page' => $assets->lastPage(),
            ],
        ]);
    }

    public function store(StoreAssetRequest $request, CustomFieldValueService $customFields)
    {
        $project = $this->resolveProject();
        $this->authorize('create', [Asset::class, $project]);

        $attributes = $request->validated();
        $attributes['metadata'] = $customFields->validateAssetMetadata($project->id, $attributes['metadata'] ?? []);
        $asset = Asset::create(array_merge($attributes, [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]));

        return response()->json([
            'success' => true,
            'data' => new AssetResource($asset),
            'message' => 'Asset created successfully',
        ], 201);
    }

    public function show(Asset $asset)
    {
        $this->authorize('view', $asset);

        $asset->load([
            'locationAssignment.location',
            'deviceBindings' => fn ($q) => $q->whereNull('unbound_at'),
            'deviceBindings.device.deviceType',
            'deviceBindings.device.integration',
        ]);

        return response()->json([
            'success' => true,
            'data' => new AssetResource($asset),
        ]);
    }

    /**
     * Activity timeline for one asset: audit entries plus integration events.
     */
    public function activity(Asset $asset)
    {
        $this->authorize('view', $asset);

        $activity = ActivityLog::where('project_id', $asset->project_id)
            ->where('resource_type', 'Asset')
            ->where('resource_id', $asset->id)
            ->with('user:id,name')
            ->latest('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'kind' => 'activity',
                'type' => $log->action,
                'actor' => $log->user?->name,
                'source' => null,
                'occurred_at' => $log->occurred_at?->toIso8601String(),
            ]);

        $events = EventLog::where('project_id', $asset->project_id)
            ->where('asset_id', $asset->id)
            ->latest('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn (EventLog $event) => [
                'kind' => 'event',
                'type' => $event->event_type,
                'actor' => null,
                'source' => $event->source,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $activity->concat($events)->sortByDesc('occurred_at')->take(50)->values(),
        ]);
    }

    public function update(UpdateAssetRequest $request, Asset $asset, CustomFieldValueService $customFields)
    {
        $this->authorize('update', $asset);

        $attributes = $request->validated();
        if (array_key_exists('metadata', $attributes)) $attributes['metadata'] = $customFields->validateAssetMetadata($asset->project_id, $attributes['metadata'] ?? [], $asset->metadata ?? []);
        $asset->update($attributes);

        return response()->json([
            'success' => true,
            'data' => new AssetResource($asset->fresh()),
            'message' => 'Asset updated successfully',
        ]);
    }

    public function destroy(Asset $asset)
    {
        $this->authorize('delete', $asset);

        $asset->delete();

        return response()->json([
            'success' => true,
            'message' => 'Asset deleted successfully',
        ]);
    }
}
