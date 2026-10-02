<?php

namespace App\Services;

use App\Events\AssetLocationUpdated;
use App\Models\Asset;
use App\Models\AssetLocation;
use App\Models\Location;
use App\Models\Movement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records asset movements and keeps AssetLocation (the current-location
 * pointer) in sync — the two writes that must always happen together, per
 * the asset.location.updated flow in docs/architecture/events.md.
 */
class MovementService
{
    /**
     * @param array{
     *   to_location_id?: int|null,
     *   from_location_id?: int|null,
     *   source?: string,
     *   recorded_by?: int|null,
     *   metadata?: array|null,
     *   occurred_at?: \DateTimeInterface|string|null,
     * } $data
     */
    public function recordMovement(Asset $asset, array $data): Movement
    {
        $toLocationId = $data['to_location_id'] ?? null;
        $fromLocationId = $data['from_location_id']
            ?? $asset->locationAssignment?->location_id;
        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();
        $source = $data['source'] ?? 'manual';

        return DB::transaction(function () use ($asset, $data, $toLocationId, $fromLocationId, $occurredAt, $source) {
            $movement = Movement::create([
                'organization_id' => $asset->organization_id,
                'project_id' => $asset->project_id,
                'asset_id' => $asset->id,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'source' => $source,
                'recorded_by' => $data['recorded_by'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'occurred_at' => $occurredAt,
            ]);

            AssetLocation::updateOrCreate(
                ['asset_id' => $asset->id],
                [
                    'organization_id' => $asset->organization_id,
                    'project_id' => $asset->project_id,
                    'location_id' => $toLocationId,
                    'source' => $source,
                    'metadata' => $data['metadata'] ?? [],
                    'arrived_at' => $occurredAt,
                ]
            );

            $asset->unsetRelation('locationAssignment');

            if ($toLocationId && ($location = Location::find($toLocationId))) {
                // Dispatched after commit (WebhookTriggerable).
                AssetLocationUpdated::dispatch($asset, $location, $source);
            }

            return $movement;
        });
    }
}
