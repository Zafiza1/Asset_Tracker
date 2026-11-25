<?php

namespace App\Http\Resources;

use App\Domain\Deliveries\Fleet;
use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Deliveries\Models\DeliveryEvent;
use App\Domain\Deliveries\Models\DeliveryItem;
use App\Domain\Deliveries\Models\DeliveryStop;
use App\Domain\Organization\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A delivery in lists and on the tracking page; its detail adds items, events and the route
 * (DeliveryController::show).
 *
 * @mixin Delivery
 */
class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fleet = app(Fleet::class);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'direction' => $this->direction,
            'direction_label' => Delivery::DIRECTIONS[$this->direction],
            'status' => $this->status,
            'status_label' => Delivery::STATUSES[$this->status],
            'planned_date' => $this->planned_date->toDateString(),
            'expected_return_date' => $this->expected_return_date?->toDateString(),
            'notes' => $this->notes,
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle === null ? null : [
                'id' => $this->vehicle->id, 'name' => $this->vehicle->name, 'plate' => $fleet->plate($this->vehicle),
            ]),
            'driver' => $this->whenLoaded('driver', fn () => self::person($this->driver)),
            'helper' => $this->whenLoaded('helper', fn () => self::person($this->helper)),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'departed_at' => $this->departed_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'end_note' => $this->end_note,
            'stops' => $this->whenLoaded('stops', fn () => $this->stops->map(fn (DeliveryStop $s) => [
                'id' => $s->id,
                'sequence' => $s->sequence,
                'customer' => ['id' => $s->customer->id, 'name' => $s->customer->name, 'address' => $s->customer->address, 'phone' => $s->customer->phone],
                'status' => $s->status,
                'status_label' => DeliveryStop::STATUSES[$s->status],
                'arrived_at' => $s->arrived_at?->toIso8601String(),
                'done_at' => $s->done_at?->toIso8601String(),
                'receiver_name' => $s->receiver_name,
                'note' => $s->note,
            ])->values()),
            'counts' => $this->when($this->resource->getAttribute('total_count') !== null, fn () => [
                'total' => (int) $this->resource->getAttribute('total_count'),
                'loaded' => (int) $this->resource->getAttribute('loaded_count'),
                'unloaded' => (int) $this->resource->getAttribute('unloaded_count'),
            ]),
            'last_event' => $this->when($this->resource->relationLoaded('lastEvent'), fn () => self::event($this->resource->getRelation('lastEvent'))),
            'last_position' => $this->when($this->resource->relationLoaded('lastPosition'), fn () => self::position($this->resource->getRelation('lastPosition'))),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (DeliveryItem $i) => [
                'id' => $i->id,
                'stop_id' => $i->stop_id,
                'asset_id' => $i->asset_id,
                'name' => $i->asset->name,
                'serial_number' => $i->asset->serial_number,
                'details' => (object) $fleet->cylinderDetails($i->asset),
                'planned' => $i->planned,
                'state' => $i->state,
                'state_label' => DeliveryItem::STATES[$i->state],
                'loaded_at' => $i->loaded_at?->toIso8601String(),
                'loaded_by' => $i->loader?->name,
                'unloaded_at' => $i->unloaded_at?->toIso8601String(),
                'unloaded_by' => $i->unloader?->name,
            ])->values()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn (DeliveryEvent $e) => self::event($e))->values()),
            'route' => $this->whenLoaded('events', fn () => $this->events->whereNotNull('latitude')->map(fn (DeliveryEvent $e) => self::position($e))->values()),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function event(?DeliveryEvent $e): ?array
    {
        return $e === null ? null : [
            'id' => $e->id,
            'type' => $e->type,
            'type_label' => DeliveryEvent::TYPES[$e->type] ?? $e->type,
            'note' => $e->note,
            'stop_id' => $e->stop_id,
            'customer' => $e->stop?->customer?->name,
            'asset' => $e->asset === null ? null : trim("{$e->asset->name} {$e->asset->serial_number}"),
            'actor' => $e->actor?->name,
            'position' => self::position($e),
            'recorded_at' => $e->recorded_at->toIso8601String(),
        ];
    }

    /** @return array{latitude: float, longitude: float, accuracy: float|null, type: string, recorded_at: string}|null */
    public static function position(?DeliveryEvent $e): ?array
    {
        return $e === null || $e->latitude === null ? null : [
            'latitude' => (float) $e->latitude,
            'longitude' => (float) $e->longitude,
            'accuracy' => $e->accuracy === null ? null : (float) $e->accuracy,
            'type' => $e->type,
            'recorded_at' => $e->recorded_at->toIso8601String(),
        ];
    }

    /** @return array{id: string, name: string, phone: string|null}|null */
    private static function person(?Employee $employee): ?array
    {
        return $employee === null ? null : ['id' => $employee->id, 'name' => $employee->name, 'phone' => $employee->phone];
    }
}
