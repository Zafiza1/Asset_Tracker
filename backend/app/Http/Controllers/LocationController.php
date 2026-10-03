<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    use ResolvesProject;

    public function index(Request $request)
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Location::class, $project]);

        $query = Location::query();

        if ($search = $request->query('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        if ($type = $request->query('type')) {
            $query->ofType($type);
        }

        $sort = (string) $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (in_array($column, ['name', 'created_at', 'type'], true)) {
            $query->orderBy($column, $direction);
        }

        $perPage = $this->perPage($request);
        $locations = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => LocationResource::collection($locations->items()),
            'meta' => [
                'current_page' => $locations->currentPage(),
                'per_page' => $locations->perPage(),
                'total' => $locations->total(),
                'last_page' => $locations->lastPage(),
            ],
        ]);
    }

    public function store(StoreLocationRequest $request)
    {
        $project = $this->resolveProject();
        $this->authorize('create', [Location::class, $project]);

        $location = Location::create(array_merge($request->validated(), [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
        ]));

        return response()->json([
            'success' => true,
            'data' => new LocationResource($location),
            'message' => 'Location created successfully',
        ], 201);
    }

    public function show(Location $location)
    {
        $this->authorize('view', $location);

        return response()->json([
            'success' => true,
            'data' => new LocationResource($location),
        ]);
    }

    public function update(UpdateLocationRequest $request, Location $location)
    {
        $this->authorize('update', $location);

        $location->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new LocationResource($location->fresh()),
            'message' => 'Location updated successfully',
        ]);
    }

    public function destroy(Location $location)
    {
        $this->authorize('delete', $location);

        $location->delete();

        return response()->json([
            'success' => true,
            'message' => 'Location deleted successfully',
        ]);
    }
}
