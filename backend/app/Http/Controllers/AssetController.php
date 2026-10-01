<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
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

        $asset->load('locationAssignment.location');

        return response()->json([
            'success' => true,
            'data' => new AssetResource($asset),
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
