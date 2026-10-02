<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/dashboard — the project overview (Phase 15): asset totals,
 * distribution, integration health and recent asset activity.
 */
class DashboardController extends Controller
{
    use ResolvesProject;

    public function __invoke(): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Asset::class, $project]);

        $assets = fn () => Asset::where('project_id', $project->id);
        $offlineBefore = now()->subMinutes(config('platform.offline_after_minutes', 60));

        // "Offline": tracked by at least one bound device, but not seen by
        // any integration within the threshold. Untracked assets are neither.
        $tracked = $assets()->whereHas('deviceBindings', fn ($q) => $q->whereNull('unbound_at'));
        $offline = (clone $tracked)->where(fn ($q) => $q
            ->whereNull('last_seen_at')
            ->orWhere('last_seen_at', '<', $offlineBefore));

        $byLocation = AssetLocation::where('asset_locations.project_id', $project->id)
            ->join('locations', 'locations.id', '=', 'asset_locations.location_id')
            ->join('assets', 'assets.id', '=', 'asset_locations.asset_id')
            ->whereNull('assets.deleted_at')
            ->selectRaw('locations.id, locations.name, COUNT(*) as count')
            ->groupBy('locations.id', 'locations.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['location_id' => $row->id, 'name' => $row->name, 'count' => (int) $row->count]);

        $recent = ActivityLog::where('project_id', $project->id)
            ->where('resource_type', 'Asset')
            ->with('user:id,name')
            ->latest('occurred_at')
            ->limit(10)
            ->get();

        // Not every action's metadata carries the asset name (status and
        // location changes don't), so resolve current names in one query.
        // withTrashed keeps deleted assets labelled.
        $assetNames = Asset::withTrashed()
            ->whereIn('id', $recent->pluck('resource_id')->filter()->unique())
            ->pluck('name', 'id');

        $recent = $recent
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'asset_system_id' => $log->metadata['system_id'] ?? null,
                'asset_name' => $assetNames[$log->resource_id] ?? $log->metadata['name'] ?? null,
                'user' => $log->user?->name,
                'occurred_at' => $log->occurred_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'totals' => [
                    'assets' => $assets()->count(),
                    'active' => $assets()->where('status', 'active')->count(),
                    'tracked' => $tracked->count(),
                    'offline' => $offline->count(),
                ],
                'by_status' => $this->countBy($assets(), 'status'),
                'by_type' => $this->countBy($assets(), 'asset_type'),
                'by_location' => $byLocation,
                'integrations' => Integration::where('project_id', $project->id)
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status'),
                'recent_activity' => $recent,
                'offline_after_minutes' => (int) config('platform.offline_after_minutes', 60),
            ],
        ]);
    }

    protected function countBy($query, string $column): array
    {
        return $query->selectRaw("COALESCE({$column}, 'unspecified') as bucket, COUNT(*) as count")
            ->groupByRaw("COALESCE({$column}, 'unspecified')")
            ->pluck('count', 'bucket')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
