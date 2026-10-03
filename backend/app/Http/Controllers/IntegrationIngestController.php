<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesMachineAccess;
use App\Http\Resources\EventLogResource;
use App\Integrations\Contracts\IngestsReadings;
use App\Models\Integration;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/integrations/{integration}/ingest — the single entry point
 * for gateways/providers to push raw readings. The integration's type picks
 * the ingestor (config/platform.php "ingestors"); Core only ever sees the
 * normalized result.
 */
class IntegrationIngestController extends Controller
{
    use AuthorizesMachineAccess;

    public function __invoke(Request $request, Integration $integration): JsonResponse
    {
        $this->authorizeMachineOrUser($request, 'integration.ingest', 'integration.configure', $integration);

        if ($integration->status === 'disconnected') {
            return response()->json([
                'success' => false,
                'message' => 'Integration is disconnected',
            ], 409);
        }

        $ingestorClass = config("platform.ingestors.{$integration->type}");

        if (!$ingestorClass || !is_subclass_of($ingestorClass, IngestsReadings::class)) {
            return response()->json([
                'success' => false,
                'message' => "Integration type '{$integration->type}' does not accept readings",
            ], 422);
        }

        $readings = $request->has('readings') ? $request->input('readings') : [$request->all()];

        if (!is_array($readings) || $readings === [] || count($readings) > 500 || array_filter($readings, fn ($r) => !is_array($r))) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => ['readings' => ['Provide one reading object or a "readings" array of 1-500 objects']],
            ], 422);
        }

        /** @var IngestsReadings $ingestor */
        $ingestor = app($ingestorClass);
        $accepted = [];
        $errors = [];

        // A malformed reading is reported back; it never aborts the batch or
        // affects Core state.
        foreach (array_values($readings) as $index => $reading) {
            try {
                $accepted[] = new EventLogResource($ingestor->ingest($integration, $reading));
            } catch (Exception $e) {
                $errors[] = ['index' => $index, 'error' => $this->safeMessage($e)];
            }
        }

        return response()->json([
            'success' => $accepted !== [],
            'data' => ['accepted' => $accepted, 'errors' => $errors],
            'message' => count($accepted) . ' reading(s) accepted, ' . count($errors) . ' rejected',
        ], $accepted === [] ? 422 : 202);
    }
}
