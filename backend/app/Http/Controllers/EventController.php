<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesMachineAccess;
use App\Http\Resources\EventLogResource;
use App\Services\EventIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/events — publish an event in the standard contract.
 * Callable by gateways/external systems (X-Api-Key with scope
 * event.ingest) or users holding the event.ingest permission.
 */
class EventController extends Controller
{
    use AuthorizesMachineAccess;

    public function store(Request $request, EventIngestionService $ingestion): JsonResponse
    {
        $this->authorizeMachineOrUser($request, 'event.ingest', 'event.ingest');
        [$organizationId, $projectId] = $this->requireProjectContext();

        $validated = $request->validate([
            'event' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'],
            'asset_id' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer'],
            'timestamp' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ]);

        $eventLog = $ingestion->ingest($validated, $organizationId, $projectId);

        return response()->json([
            'success' => true,
            'data' => new EventLogResource($eventLog),
            'message' => 'Event accepted',
        ], 202);
    }
}
