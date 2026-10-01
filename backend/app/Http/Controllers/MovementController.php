<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMovementRequest;
use App\Http\Resources\MovementResource;
use App\Models\Asset;
use App\Models\Movement;
use App\Services\MovementService;
use Illuminate\Http\Request;

class MovementController extends Controller
{
    public function index(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);
        $this->authorize('viewAny', [Movement::class, $asset->project]);

        $perPage = min((int) $request->query('per_page', 25), 100);
        $movements = $asset->movements()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => MovementResource::collection($movements->items()),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'last_page' => $movements->lastPage(),
            ],
        ]);
    }

    public function store(StoreMovementRequest $request, Asset $asset, MovementService $movementService)
    {
        $this->authorize('view', $asset);
        $this->authorize('create', [Movement::class, $asset->project]);

        $movement = $movementService->recordMovement($asset, array_merge($request->validated(), [
            'recorded_by' => $request->user()->id,
        ]));

        return response()->json([
            'success' => true,
            'data' => new MovementResource($movement),
            'message' => 'Movement recorded successfully',
        ], 201);
    }
}
