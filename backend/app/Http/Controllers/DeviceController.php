<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\DeviceTypeResource;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\Asset;
use App\Services\DeviceService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    use ResolvesProject;

    protected DeviceService $deviceService;

    public function __construct(DeviceService $deviceService)
    {
        $this->deviceService = $deviceService;
    }

    public function index(Request $request): JsonResponse
    {
        $devices = Device::with(['deviceType', 'currentBinding.asset'])
            ->when($request->type, fn($q) => $q->ofType($request->type))
            ->when($request->status, fn($q) => $q->ofStatus($request->status))
            ->when($request->with_asset, fn($q) => $q->withAsset())
            ->paginate($request->per_page ?? 25);

        return response()->json([
            'success' => true,
            'data' => DeviceResource::collection($devices),
            'meta' => [
                'total' => $devices->total(),
                'per_page' => $devices->perPage(),
                'current_page' => $devices->currentPage(),
                'last_page' => $devices->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_type_id' => 'required|exists:device_types,id',
            'integration_id' => 'nullable|exists:integrations,id',
            'serial_number' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'status' => 'nullable|in:offline,online,error',
            'metadata' => 'nullable|array',
        ]);

        $project = $this->resolveProject();
        $this->authorize('create', [Device::class, $project]);

        $validated['organization_id'] = $project->organization_id;
        $validated['project_id'] = $project->id;

        $device = $this->deviceService->createDevice($validated);

        return response()->json([
            'success' => true,
            'data' => new DeviceResource($device->load('deviceType')),
            'message' => 'Device created successfully',
        ], 201);
    }

    public function show(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $device->load(['deviceType', 'integration', 'currentBinding.asset']);

        return response()->json([
            'success' => true,
            'data' => new DeviceResource($device),
        ]);
    }

    public function update(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'device_type_id' => 'sometimes|exists:device_types,id',
            'integration_id' => 'sometimes|nullable|exists:integrations,id',
            'serial_number' => 'sometimes|nullable|string|max:255',
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:offline,online,error',
            'metadata' => 'nullable|array',
        ]);

        $device = $this->deviceService->updateDevice($device, $validated);

        return response()->json([
            'success' => true,
            'data' => new DeviceResource($device->load('deviceType')),
            'message' => 'Device updated successfully',
        ]);
    }

    public function destroy(Device $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $this->deviceService->deleteDevice($device);

        return response()->json([
            'success' => true,
            'message' => 'Device deleted successfully',
        ]);
    }

    public function bind(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'asset_id' => 'required|exists:assets,id',
        ]);

        $asset = Asset::findOrFail($validated['asset_id']);

        $this->authorize('update', $asset);

        $binding = $this->deviceService->bindDevice($device, $asset);

        return response()->json([
            'success' => true,
            'data' => [
                'device_id' => $device->id,
                'asset_id' => $asset->id,
                'bound_at' => $binding->bound_at->toIso8601String(),
            ],
            'message' => 'Device bound to asset successfully',
        ]);
    }

    public function unbind(Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $result = $this->deviceService->unbindDevice($device);

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Device is not bound to any asset',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Device unbound successfully',
        ]);
    }

    public function bindings(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $bindings = $this->deviceService->getDeviceBindings(
            $device,
            $request->query('active_only', true)
        );

        return response()->json([
            'success' => true,
            'data' => $bindings,
        ]);
    }

    public function updateStatus(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $validated = $request->validate([
            'status' => 'required|in:offline,online,error',
        ]);

        $device = $this->deviceService->updateDeviceStatus($device, $validated['status']);

        return response()->json([
            'success' => true,
            'data' => new DeviceResource($device),
            'message' => 'Device status updated successfully',
        ]);
    }

    // Device Type endpoints
    public function indexTypes(Request $request): JsonResponse
    {
        $types = DeviceType::when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->paginate($request->per_page ?? 25);

        return response()->json([
            'success' => true,
            'data' => DeviceTypeResource::collection($types),
            'meta' => [
                'total' => $types->total(),
                'per_page' => $types->perPage(),
                'current_page' => $types->currentPage(),
                'last_page' => $types->lastPage(),
            ],
        ]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:device_types,slug',
            'description' => 'nullable|string',
            'capabilities' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $type = $this->deviceService->createDeviceType($validated);

        return response()->json([
            'success' => true,
            'data' => new DeviceTypeResource($type),
            'message' => 'Device type created successfully',
        ], 201);
    }

    public function showType(DeviceType $deviceType): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new DeviceTypeResource($deviceType),
        ]);
    }

    public function updateType(Request $request, DeviceType $deviceType): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:device_types,slug,' . $deviceType->id,
            'description' => 'nullable|string',
            'capabilities' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $type = $this->deviceService->updateDeviceType($deviceType, $validated);

        return response()->json([
            'success' => true,
            'data' => new DeviceTypeResource($type),
            'message' => 'Device type updated successfully',
        ]);
    }

    public function destroyType(DeviceType $deviceType): JsonResponse
    {
        try {
            $this->deviceService->deleteDeviceType($deviceType);

            return response()->json([
                'success' => true,
                'message' => 'Device type deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
