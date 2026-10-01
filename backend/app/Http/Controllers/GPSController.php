<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Resources\DeviceResource;
use App\Models\Integration;
use App\Services\GPSService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GPSController extends Controller
{
    use ResolvesProject;

    protected GPSService $gpsService;

    public function __construct(GPSService $gpsService)
    {
        $this->gpsService = $gpsService;
    }

    /**
     * Register a GPS tracker as a device
     */
    public function registerTracker(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'name' => 'nullable|string|max:255',
            'tracker_type' => 'nullable|in:standalone,vehicle,asset',
            'battery_type' => 'nullable|string|max:50',
            'firmware_version' => 'nullable|string|max:50',
            'update_interval' => 'nullable|integer|min:10|max:3600',
        ]);

        if (!$this->gpsService->validateDeviceId($validated['device_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid device ID format',
            ], 422);
        }

        $device = $this->gpsService->registerTracker($validated, $integration);

        return response()->json([
            'success' => true,
            'data' => new DeviceResource($device->load('deviceType')),
            'message' => 'GPS tracker registered successfully',
        ], 201);
    }

    /**
     * Ingest a single GPS location update event
     */
    public function ingestLocationUpdate(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'timestamp' => 'nullable|date',
            'speed' => 'nullable|numeric',
            'heading' => 'nullable|numeric|between:0,360',
            'altitude' => 'nullable|numeric',
            'accuracy' => 'nullable|numeric',
            'address' => 'nullable|string|max:500',
            'provider' => 'nullable|string|max:50',
            'fix_type' => 'nullable|string|max:50',
            'satellite_count' => 'nullable|integer',
        ]);

        try {
            $eventLog = $this->gpsService->processLocationUpdate($integration, $validated);

            return response()->json([
                'success' => true,
                'data' => [
                    'event_log_id' => $eventLog->id,
                    'event_type' => $eventLog->event_type,
                    'asset_id' => $eventLog->asset_id,
                    'device_id' => $eventLog->payload['device_serial'],
                    'latitude' => $eventLog->payload['latitude'],
                    'longitude' => $eventLog->payload['longitude'],
                    'occurred_at' => $eventLog->occurred_at->toIso8601String(),
                ],
                'message' => 'GPS location update processed successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Bulk ingest GPS location updates
     */
    public function bulkIngestLocationUpdates(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'updates' => 'required|array|min:1|max:100',
            'updates.*.device_id' => 'required|string|max:255',
            'updates.*.latitude' => 'required|numeric|between:-90,90',
            'updates.*.longitude' => 'required|numeric|between:-180,180',
            'updates.*.timestamp' => 'nullable|date',
            'updates.*.speed' => 'nullable|numeric',
        ]);

        $results = $this->gpsService->bulkIngestLocationUpdates($validated['updates'], $integration);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Processed {$results['processed']} updates, {$results['failed']} failed",
        ]);
    }

    /**
     * Get GPS devices for an integration
     */
    public function getDevices(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $devices = $this->gpsService->getGPSDevices($integration);

        return response()->json([
            'success' => true,
            'data' => DeviceResource::collection($devices),
        ]);
    }

    /**
     * Get location update statistics
     */
    public function getStats(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $hours = (int) $request->query('hours', 24);
        $stats = $this->gpsService->getLocationUpdateStats($integration, $hours);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Bulk register GPS trackers
     */
    public function bulkRegisterTrackers(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'trackers' => 'required|array|min:1|max:100',
            'trackers.*.device_id' => 'required|string|max:255',
            'trackers.*.name' => 'nullable|string|max:255',
            'trackers.*.tracker_type' => 'nullable|in:standalone,vehicle,asset',
        ]);

        $results = $this->gpsService->bulkRegisterTrackers($validated['trackers'], $integration);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Registered {$results['success']} trackers, {$results['failed']} failed",
        ]);
    }

    /**
     * Get current location of an asset
     */
    public function getAssetLocation(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $validated = $request->validate([
            'asset_id' => 'required|integer',
        ]);

        $asset = \App\Models\Asset::where('id', $validated['asset_id'])
            ->where('project_id', $integration->project_id)
            ->firstOrFail();

        $location = $this->gpsService->getAssetCurrentLocation($asset);

        if (!$location) {
            return response()->json([
                'success' => false,
                'message' => 'No current location found for asset',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $location,
        ]);
    }

    /**
     * Get location history for an asset
     */
    public function getAssetLocationHistory(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $validated = $request->validate([
            'asset_id' => 'required|integer',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $asset = \App\Models\Asset::where('id', $validated['asset_id'])
            ->where('project_id', $integration->project_id)
            ->firstOrFail();

        $limit = $validated['limit'] ?? 100;
        $history = $this->gpsService->getAssetLocationHistory($asset, $limit);

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }
}
