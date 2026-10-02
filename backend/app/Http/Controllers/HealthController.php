<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * GET /api/health — platform components (public, no tenant data).
 * GET /api/v1/health/integrations — integration status for the project.
 *
 * Only the database is critical (503); Redis or queue problems degrade the
 * platform but Core keeps serving.
 */
class HealthController extends Controller
{
    use ResolvesProject;

    public function platform(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->usesRedis()
                ? $this->check(fn () => Redis::connection()->ping())
                : ['status' => 'not_configured'],
            'queue' => $this->check(fn () => ['connection' => config('queue.default'), 'size' => Queue::size()]),
        ];

        $status = match (true) {
            $checks['database']['status'] !== 'healthy' => 'unhealthy',
            in_array('unhealthy', array_column($checks, 'status'), true) => 'degraded',
            default => 'healthy',
        };

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $status === 'unhealthy' ? 503 : 200);
    }

    public function integrations(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Integration::class, $project]);

        $integrations = Integration::where('project_id', $project->id)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'status', 'last_connected_at', 'last_health_check_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $integrations->countBy('status'),
                'integrations' => $integrations->map(fn (Integration $integration) => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'type' => $integration->type,
                    'status' => $integration->status,
                    'health' => match ($integration->status) {
                        'connected' => 'healthy',
                        'degraded' => 'degraded',
                        default => 'unavailable',
                    },
                    'last_health_check_at' => $integration->last_health_check_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    protected function usesRedis(): bool
    {
        return in_array('redis', [config('queue.default'), config('cache.default'), config('session.driver')], true);
    }

    /**
     * @return array{status: string, details?: mixed, error?: string}
     */
    protected function check(callable $probe): array
    {
        try {
            $result = $probe();

            return is_array($result) && !array_is_list($result)
                ? ['status' => 'healthy', 'details' => $result]
                : ['status' => 'healthy'];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'unhealthy', 'error' => class_basename($e)];
        }
    }
}
