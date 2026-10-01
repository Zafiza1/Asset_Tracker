<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Resources\IntegrationResource;
use App\Models\Integration;
use App\Services\IntegrationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class IntegrationController extends Controller
{
    use ResolvesProject;

    protected IntegrationService $integrationService;

    public function __construct(IntegrationService $integrationService)
    {
        $this->integrationService = $integrationService;
    }

    public function index(Request $request): JsonResponse
    {
        $integrations = Integration::with(['configs' => function ($query) {
            $query->notSecret();
        }])
            ->when($request->type, fn($q) => $q->ofType($request->type))
            ->when($request->status, fn($q) => $q->ofStatus($request->status))
            ->paginate($request->per_page ?? 25);

        return response()->json([
            'success' => true,
            'data' => IntegrationResource::collection($integrations),
            'meta' => [
                'total' => $integrations->total(),
                'per_page' => $integrations->perPage(),
                'current_page' => $integrations->currentPage(),
                'last_page' => $integrations->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:rfid,gps,ble,nfc,lorawan,iot,barcode,qr_code,external_api',
            'provider' => 'nullable|string|max:255',
            'config' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $project = $this->resolveProject();
        $this->authorize('create', [Integration::class, $project]);

        $validated['organization_id'] = $project->organization_id;
        $validated['project_id'] = $project->id;

        $integration = $this->integrationService->createIntegration($validated);

        return response()->json([
            'success' => true,
            'data' => new IntegrationResource($integration->load('configs')),
            'message' => 'Integration created successfully',
        ], 201);
    }

    public function show(Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $integration->load(['configs' => function ($query) {
            $query->notSecret();
        }]);

        return response()->json([
            'success' => true,
            'data' => new IntegrationResource($integration),
        ]);
    }

    public function update(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'provider' => 'sometimes|string|max:255',
            'config' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        $integration = $this->integrationService->updateIntegration($integration, $validated);

        return response()->json([
            'success' => true,
            'data' => new IntegrationResource($integration->load('configs')),
            'message' => 'Integration updated successfully',
        ]);
    }

    public function destroy(Integration $integration): JsonResponse
    {
        $this->authorize('delete', $integration);

        $integration->delete();

        return response()->json([
            'success' => true,
            'message' => 'Integration deleted successfully',
        ]);
    }

    public function connect(Integration $integration): JsonResponse
    {
        $this->authorize('connect', $integration);

        $result = $this->integrationService->connectIntegration($integration);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    public function disconnect(Integration $integration): JsonResponse
    {
        $this->authorize('disconnect', $integration);

        $result = $this->integrationService->disconnectIntegration($integration);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    public function testConnection(Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $result = $this->integrationService->testConnection($integration);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    public function healthCheck(Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $result = $this->integrationService->healthCheck($integration);

        return response()->json($result);
    }

    public function getAvailableIntegrations(): JsonResponse
    {
        $integrations = $this->integrationService->getAvailableIntegrations();

        return response()->json([
            'success' => true,
            'data' => $integrations,
        ]);
    }

    public function validateConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'config' => 'required|array',
        ]);

        $result = $this->integrationService->validateIntegrationConfig(
            $validated['type'],
            $validated['config']
        );

        return response()->json($result);
    }
}
