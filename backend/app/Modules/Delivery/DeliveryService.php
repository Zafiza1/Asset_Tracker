<?php

namespace App\Modules\Delivery;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Modules\Concerns\ChecksProjectLocations;
use App\Modules\Customer\Models\Customer;
use App\Modules\Delivery\Events\DeliveryChanged;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Models\DeliveryItem;
use App\Modules\ModuleSettings;
use App\Services\AuditService;
use App\Services\MovementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Delivery module business logic.
 *
 *   pending ──dispatch──► in_transit ──deliver──► delivered ──return──► returned
 *      └─────────────cancel────────────┘
 *
 * Assets change position only through Core's MovementService (source
 * "delivery"), so movement history, the current location, the
 * asset.location.updated event and webhooks behave as for any other move.
 */
class DeliveryService
{
    use ChecksProjectLocations;

    public const MODULE_SLUG = 'delivery';

    public const MOVEMENT_SOURCE = 'delivery';

    public function __construct(
        protected MovementService $movements,
        protected ModuleSettings $settings,
        protected AuditService $audit,
    ) {
    }

    /**
     * @param Collection<int, Asset> $assets
     */
    public function create(Project $project, Customer $customer, Collection $assets, array $data, ?User $user = null): Delivery
    {
        if ($customer->project_id !== $project->id) {
            throw ApiException::invalid('Validation failed', ['customer_id' => ['The customer does not belong to this project']]);
        }

        if ($customer->status !== 'active') {
            throw ApiException::invalid('Validation failed', ['customer_id' => ['Deliveries can only be made to active customers']]);
        }

        if ($assets->isEmpty() || $assets->contains(fn (Asset $asset) => $asset->project_id !== $project->id)) {
            throw ApiException::invalid('Validation failed', ['asset_ids' => ['Every asset must belong to this project']]);
        }

        $destinationId = $data['destination_location_id'] ?? $customer->location_id;
        $this->assertLocationInProject($project, $destinationId, 'destination_location_id');

        return DB::transaction(function () use ($project, $customer, $assets, $data, $destinationId, $user) {
            // Lock the assets so two concurrent deliveries cannot both take one.
            Asset::withoutGlobalScopes()->whereIn('id', $assets->pluck('id'))->lockForUpdate()->get();
            $this->assertAssetsAvailable($assets);

            $delivery = Delivery::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'customer_id' => $customer->id,
                'reference' => $data['reference'] ?? null,
                'status' => 'pending',
                'destination_location_id' => $destinationId,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $user?->id,
            ]);

            foreach ($assets as $asset) {
                $delivery->items()->create(['asset_id' => $asset->id, 'status' => 'pending']);
            }

            $this->announce($delivery, 'created', $user);

            return $delivery;
        });
    }

    /** Details can change until the delivery leaves. */
    public function update(Delivery $delivery, array $data, ?User $user = null): Delivery
    {
        $this->assertStatus($delivery, ['pending'], 'edited');

        if (array_key_exists('destination_location_id', $data)) {
            $this->assertLocationInProject($delivery->project, $data['destination_location_id'], 'destination_location_id');
        }

        $delivery->update($data);
        $this->log('delivery.updated', $delivery, $user, ['changes' => array_keys($data)]);

        return $delivery->fresh();
    }

    /**
     * The delivery leaves. With via_location_id (e.g. the truck), the assets
     * are moved there while in transit.
     */
    public function dispatch(Delivery $delivery, array $data, ?User $user = null): Delivery
    {
        $this->assertStatus($delivery, ['pending'], 'dispatched');
        $viaLocationId = $data['via_location_id'] ?? null;
        $this->assertLocationInProject($delivery->project, $viaLocationId, 'via_location_id');

        return DB::transaction(function () use ($delivery, $viaLocationId, $user) {
            $dispatchedAt = now();

            if ($viaLocationId) {
                foreach ($this->itemsWithStatus($delivery, ['pending']) as $item) {
                    $this->move($item->asset, $viaLocationId, $dispatchedAt, $delivery, 'dispatched');
                }
            }

            $delivery->update(['status' => 'in_transit', 'dispatched_at' => $dispatchedAt]);
            $this->announce($delivery, 'dispatched', $user);

            return $delivery;
        });
    }

    /**
     * The customer receives the assets: each moves to the destination.
     * received_by is required when the project enables
     * require_proof_of_delivery.
     */
    public function deliver(Delivery $delivery, array $data, ?User $user = null): Delivery
    {
        $this->assertStatus($delivery, ['pending', 'in_transit'], 'delivered');

        if (!$delivery->destination_location_id) {
            throw ApiException::invalid('Validation failed', [
                'destination_location_id' => ['Set a destination (or the customer\'s site) before delivering'],
            ]);
        }

        if ($this->settings->get($delivery->project, self::MODULE_SLUG, 'require_proof_of_delivery', false)
            && empty($data['received_by'])) {
            throw ApiException::invalid('Validation failed', ['received_by' => ['Proof of delivery is required: enter who received the assets']]);
        }

        return DB::transaction(function () use ($delivery, $data, $user) {
            $deliveredAt = isset($data['delivered_at']) ? Carbon::parse($data['delivered_at']) : now();

            foreach ($this->itemsWithStatus($delivery, ['pending']) as $item) {
                $this->move($item->asset, $delivery->destination_location_id, $deliveredAt, $delivery, 'delivered');
                $item->update(['status' => 'delivered', 'delivered_at' => $deliveredAt]);
            }

            $delivery->update([
                'status' => 'delivered',
                'dispatched_at' => $delivery->dispatched_at ?? $deliveredAt,
                'delivered_at' => $deliveredAt,
                'received_by' => $data['received_by'] ?? null,
                'notes' => $data['notes'] ?? $delivery->notes,
            ]);
            $this->announce($delivery, 'delivered', $user);

            return $delivery;
        });
    }

    /**
     * Assets come back from the customer to to_location_id. Without
     * asset_ids, every asset still out is returned. The delivery is
     * "returned" once nothing is left at the customer.
     *
     * @param array<int, string>|null $assetSystemIds
     */
    public function returnAssets(Delivery $delivery, int $toLocationId, ?array $assetSystemIds = null, ?User $user = null): Delivery
    {
        $this->assertStatus($delivery, ['delivered'], 'returned');
        $this->assertLocationInProject($delivery->project, $toLocationId, 'to_location_id');

        $outstanding = $this->itemsWithStatus($delivery, ['delivered']);
        $returning = $assetSystemIds === null
            ? $outstanding
            : $outstanding->filter(fn (DeliveryItem $item) => in_array($item->asset->system_id, $assetSystemIds, true));

        if ($assetSystemIds !== null && $returning->count() !== count(array_unique($assetSystemIds))) {
            throw ApiException::invalid('Validation failed', ['asset_ids' => ['Only assets of this delivery that are still at the customer can be returned']]);
        }

        return DB::transaction(function () use ($delivery, $toLocationId, $returning, $outstanding, $user) {
            $returnedAt = now();

            foreach ($returning as $item) {
                $this->move($item->asset, $toLocationId, $returnedAt, $delivery, 'returned');
                $item->update(['status' => 'returned', 'returned_at' => $returnedAt, 'returned_to_location_id' => $toLocationId]);
            }

            if ($returning->count() === $outstanding->count()) {
                $delivery->update(['status' => 'returned', 'returned_at' => $returnedAt]);
            }

            $this->announce($delivery, 'returned', $user, [
                'returned_assets' => $returning->map(fn (DeliveryItem $item) => $item->asset->system_id)->values()->all(),
                'to_location_id' => $toLocationId,
            ]);

            return $delivery;
        });
    }

    /**
     * Calls a delivery off before it arrives; its assets become available
     * again (they stay where they are — record a movement to bring them back).
     */
    public function cancel(Delivery $delivery, ?string $reason = null, ?User $user = null): Delivery
    {
        $this->assertStatus($delivery, ['pending', 'in_transit'], 'cancelled');

        $delivery->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'notes' => $reason ? trim(($delivery->notes ? $delivery->notes . "\n" : '') . "Cancelled: {$reason}") : $delivery->notes,
        ]);
        $this->announce($delivery, 'cancelled', $user);

        return $delivery;
    }

    /**
     * @param Collection<int, Asset> $assets
     */
    protected function assertAssetsAvailable(Collection $assets): void
    {
        $busy = DeliveryItem::query()
            ->whereIn('asset_id', $assets->pluck('id'))
            ->whereIn('status', ['pending', 'delivered'])
            ->whereHas('delivery', fn ($q) => $q->withoutGlobalScopes()->whereIn('status', Delivery::OPEN_STATUSES))
            ->with('asset:id,system_id')
            ->get();

        if ($busy->isNotEmpty()) {
            $ids = $busy->map(fn (DeliveryItem $item) => $item->asset->system_id)->unique()->implode(', ');
            throw ApiException::conflict("Already part of an open delivery: {$ids}");
        }
    }

    protected function assertStatus(Delivery $delivery, array $allowed, string $action): void
    {
        if (!in_array($delivery->status, $allowed, true)) {
            throw ApiException::conflict("A {$delivery->status} delivery cannot be {$action}");
        }
    }

    /** @return Collection<int, DeliveryItem> */
    protected function itemsWithStatus(Delivery $delivery, array $statuses): Collection
    {
        return $delivery->items()->whereIn('status', $statuses)->with('asset')->get();
    }

    protected function move(Asset $asset, int $locationId, \DateTimeInterface $at, Delivery $delivery, string $step): void
    {
        $this->movements->recordMovement($asset, [
            'to_location_id' => $locationId,
            'source' => self::MOVEMENT_SOURCE,
            'occurred_at' => $at,
            'metadata' => [
                'delivery_id' => $delivery->id,
                'delivery_reference' => $delivery->reference,
                'customer_id' => $delivery->customer_id,
                'step' => $step,
            ],
        ]);
    }

    protected function announce(Delivery $delivery, string $action, ?User $user, array $extra = []): void
    {
        $delivery->load('customer', 'items.asset');
        DeliveryChanged::dispatch($delivery, $action, $extra);
        $this->log("delivery.{$action}", $delivery, $user, $extra);
    }

    protected function log(string $action, Delivery $delivery, ?User $user, array $extra = []): void
    {
        $this->audit->logActivity(
            $action,
            'delivery',
            $delivery->id,
            array_merge(['status' => $delivery->status, 'customer_id' => $delivery->customer_id], $extra),
            $user?->id,
            $delivery->organization_id,
            $delivery->project_id,
        );
    }
}
