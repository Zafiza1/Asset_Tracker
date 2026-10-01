<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Resources\DeviceResource;
use App\Models\Integration;
use App\Services\RFIDService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RFIDController extends Controller
{
    use ResolvesProject;

    protected RFIDService $rfidService;

    public function __construct(RFIDService $rfidService)
    {
        $this->rfidService = $rfidService;
    }

    /**
     * Register an RFID tag as a device
     */
    public function registerTag(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'tag_id' => 'required|string|max:255',
            'name' => 'nullable|string|max:255',
            'tag_type' => 'nullable|in:passive,active,semi_passive',
            'frequency' => 'nullable|string|max:50',
            'manufacturer' => 'nullable|string|max:255',
            'memory_size' => 'nullable|integer',
        ]);

        if (!$this->rfidService->validateTagId($validated['tag_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid tag ID format',
            ], 422);
        }

        $device = $this->rfidService->registerTag($validated, $integration);

        return response()->json([
            'success' => true,
            'data' => new DeviceResource($device->load('deviceType')),
            'message' => 'RFID tag registered successfully',
        ], 201);
    }

    /**
     * Register an RFID reader as a device
     */
    public function registerReader(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'reader_id' => 'required|string|max:255',
            'name' => 'nullable|string|max:255',
            'reader_type' => 'nullable|in:fixed,handheld,portal',
            'antenna_count' => 'nullable|integer|min:1|max:32',
            'read_power' => 'nullable|numeric',
            'location' => 'nullable|string|max:255',
        ]);

        if (!$this->rfidService->validateReaderId($validated['reader_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reader ID format',
            ], 422);
        }

        $device = $this->rfidService->registerReader($validated, $integration);

        return response()->json([
            'success' => true,
            'data' => new DeviceResource($device->load('deviceType')),
            'message' => 'RFID reader registered successfully',
        ], 201);
    }

    /**
     * Ingest a single RFID tag read event
     */
    public function ingestTagRead(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'tag_id' => 'required|string|max:255',
            'reader_id' => 'required|string|max:255',
            'timestamp' => 'nullable|date',
            'rssi' => 'nullable|numeric',
            'location' => 'nullable|string|max:255',
            'antenna_port' => 'nullable|integer',
            'reader_type' => 'nullable|string|max:50',
            'read_count' => 'nullable|integer|min:1',
        ]);

        try {
            $eventLog = $this->rfidService->processTagRead($integration, $validated);

            return response()->json([
                'success' => true,
                'data' => [
                    'event_log_id' => $eventLog->id,
                    'event_type' => $eventLog->event_type,
                    'asset_id' => $eventLog->asset_id,
                    'tag_id' => $eventLog->payload['tag_id'],
                    'occurred_at' => $eventLog->occurred_at->toIso8601String(),
                ],
                'message' => 'RFID tag read processed successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Bulk ingest RFID tag read events
     */
    public function bulkIngestTagReads(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'reads' => 'required|array|min:1|max:100',
            'reads.*.tag_id' => 'required|string|max:255',
            'reads.*.reader_id' => 'required|string|max:255',
            'reads.*.timestamp' => 'nullable|date',
            'reads.*.rssi' => 'nullable|numeric',
        ]);

        $results = [
            'processed' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($validated['reads'] as $readData) {
            try {
                $this->rfidService->processTagRead($integration, $readData);
                $results['processed']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'tag_id' => $readData['tag_id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Processed {$results['processed']} reads, {$results['failed']} failed",
        ]);
    }

    /**
     * Get RFID devices for an integration
     */
    public function getDevices(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $deviceType = $request->query('type'); // 'tag' or 'reader'
        $devices = $this->rfidService->getRFIDDevices($integration, $deviceType);

        return response()->json([
            'success' => true,
            'data' => DeviceResource::collection($devices),
        ]);
    }

    /**
     * Get tag read statistics
     */
    public function getStats(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('view', $integration);

        $hours = (int) $request->query('hours', 24);
        $stats = $this->rfidService->getTagReadStats($integration, $hours);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Bulk register RFID tags
     */
    public function bulkRegisterTags(Request $request, Integration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $validated = $request->validate([
            'tags' => 'required|array|min:1|max:100',
            'tags.*.tag_id' => 'required|string|max:255',
            'tags.*.name' => 'nullable|string|max:255',
            'tags.*.tag_type' => 'nullable|in:passive,active,semi_passive',
        ]);

        $results = $this->rfidService->bulkRegisterTags($validated['tags'], $integration);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => "Registered {$results['success']} tags, {$results['failed']} failed",
        ]);
    }
}
