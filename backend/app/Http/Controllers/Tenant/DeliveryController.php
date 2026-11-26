<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\Models\Asset;
use App\Domain\Deliveries\Fleet;
use App\Domain\Deliveries\Models\Delivery;
use App\Domain\Deliveries\Models\DeliveryEvent;
use App\Domain\Deliveries\Models\DeliveryItem;
use App\Domain\Deliveries\Services\DeliveryService;
use App\Domain\Organization\Models\Customer;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\Vehicle;
use App\Domain\Shared\Masters\MasterStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Http\Support\ListQuery;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Incoming and outgoing transactions of cylinders, and their tracking. */
class DeliveryController extends Controller
{
    private const RELATIONS = ['vehicle', 'driver', 'helper', 'creator', 'stops.customer'];

    /** Most events in the detail; a long trip keeps its newest ones. */
    private const MAX_EVENTS = 1000;

    private const MAX_CYLINDERS = 50;

    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly Fleet $fleet,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'direction' => ['nullable', Rule::in(array_keys(Delivery::DIRECTIONS))],
            'status' => ['nullable', Rule::in([...array_keys(Delivery::STATUSES), 'open'])],
            'customer_id' => ['nullable', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = $this->listed()
            ->when($request->query('direction'), fn ($q, $direction) => $q->where('direction', $direction))
            ->when($request->query('status'), fn ($q, $status) => $status === 'open' ? $q->whereIn('status', Delivery::OPEN) : $q->where('status', $status))
            ->when($request->query('customer_id'), fn ($q, $id) => $q->whereHas('stops', fn ($s) => $s->where('customer_id', $id)))
            ->when($request->query('vehicle_id'), fn ($q, $id) => $q->where('vehicle_id', $id))
            ->when($request->query('date_from'), fn ($q, $date) => $q->whereDate('planned_date', '>=', $date))
            ->when($request->query('date_to'), fn ($q, $date) => $q->whereDate('planned_date', '<=', $date))
            ->when($request->query('search'), function ($q, $term) {
                $like = ListQuery::like($term);
                $q->where(fn ($w) => $w->where('number', 'ilike', $like)
                    ->orWhereHas('stops.customer', fn ($c) => $c->where('name', 'ilike', $like))
                    ->orWhereHas('driver', fn ($e) => $e->where('name', 'ilike', $like))
                    ->orWhereHas('items.asset', fn ($a) => $a->where('serial_number', 'ilike', $like)));
            });
        ListQuery::sort($query, $request, ['number' => 'number', 'planned_date' => 'planned_date', 'status' => 'status', 'created_at' => 'created_at'], '-created_at');
        $page = $query->paginate(ListQuery::perPage($request))->withQueryString();
        $this->attachLast($page->getCollection());

        return DeliveryResource::collection($page);
    }

    /** Every delivery that has not ended, with where its vehicle was last seen. */
    public function tracking(): AnonymousResourceCollection
    {
        $open = $this->listed()->whereIn('status', Delivery::OPEN)
            ->orderByRaw("CASE status WHEN 'in_transit' THEN 0 WHEN 'loading' THEN 1 ELSE 2 END")
            ->orderBy('planned_date')->orderBy('id')->limit(200)->get();
        $this->attachLast($open);

        return DeliveryResource::collection($open);
    }

    /** Choices of the forms: vehicles, crew and customers. */
    public function options(): JsonResponse
    {
        $busy = Delivery::query()->where('status', Delivery::IN_TRANSIT)->get(['vehicle_id', 'driver_id', 'helper_id']);
        $busyPeople = $busy->pluck('driver_id')->merge($busy->pluck('helper_id'))->filter();
        $employees = fn (string ...$roles) => Employee::query()->with('department')->where('status', MasterStatus::ACTIVE)->withDeliveryRole($roles)->orderBy('name')->get()
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->name, 'phone' => $e->phone, 'position' => $e->department?->delivery_role,
                'division' => $e->department?->name, 'on_trip' => $busyPeople->contains($e->id)])->values();

        return response()->json(['data' => [
            'vehicles' => $this->fleet->vehicles()->orderBy('name')->get()->map(fn (Vehicle $v) => [
                'id' => $v->id, 'name' => $v->name, 'plate' => $v->plate_number, 'type' => Vehicle::TYPES[$v->type] ?? $v->type,
                'on_trip' => $busy->contains('vehicle_id', $v->id),
            ])->values(),
            'drivers' => $employees(Department::DRIVER),
            'helpers' => $employees(Department::HELPER, Department::DRIVER),
            'customers' => Customer::query()->where('status', MasterStatus::ACTIVE)->orderBy('name')->get(['id', 'code', 'name', 'address']),
            'return_days' => (int) config('edition.return_days'),
        ]]);
    }

    /** Cylinders that can be chosen: in the warehouse (outgoing) or at a customer (incoming). */
    public function cylinders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(array_keys(Delivery::DIRECTIONS))],
            'customer_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            // The delivery being edited: its own cylinders stay available.
            'delivery_id' => ['nullable', 'uuid'],
        ]);
        $query = $this->fleet->cylinders()->whereIn('status', Fleet::loadableStatuses($data['direction']))
            ->whereNotIn('id', DeliveryItem::query()->where('active', true)->when($data['delivery_id'] ?? null, fn ($q, $id) => $q->where('delivery_id', '!=', $id))->select('asset_id'))
            ->when($data['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))->orWhere('serial_number', 'ilike', ListQuery::like($term))));
        if (($data['customer_id'] ?? null) !== null && $data['direction'] === Delivery::INCOMING) {
            $query->whereRaw(<<<'SQL'
                (SELECT s.customer_id FROM delivery_items di
                   JOIN delivery_stops s ON s.id = di.stop_id JOIN deliveries d ON d.id = di.delivery_id
                  WHERE di.asset_id = assets.id AND d.direction = 'outgoing' AND di.state = 'unloaded'
                  ORDER BY di.unloaded_at DESC LIMIT 1) = ?
            SQL, [$data['customer_id']]);
        }
        $total = (clone $query)->count();
        $assets = $query->orderBy('name')->orderBy('serial_number')->limit(self::MAX_CYLINDERS)->get();
        $positions = Fleet::positions($assets->pluck('id')->all());

        return response()->json([
            'data' => $assets->map(fn (Asset $a) => [
                'id' => $a->id, 'name' => $a->name, 'serial_number' => $a->serial_number,
                'details' => (object) $this->fleet->cylinderDetails($a),
                'position' => $positions[$a->id]['label'] ?? null,
            ])->values(),
            'meta' => ['total' => $total],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->legacyVehicle($request);
        $data = $request->validate(['direction' => ['required', Rule::in(array_keys(Delivery::DIRECTIONS))], ...$this->planRules()]);

        return $this->detail($this->deliveries->create($data))->response()->setStatusCode(201);
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return $this->detail($delivery);
    }

    public function update(Request $request, Delivery $delivery): DeliveryResource
    {
        $this->legacyVehicle($request);

        return $this->detail($this->deliveries->update($delivery, $request->validate($this->planRules())));
    }

    public function cancel(Request $request, Delivery $delivery): DeliveryResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->detail($this->deliveries->cancel($delivery, $data['reason']));
    }

    public function crew(Request $request, Delivery $delivery): DeliveryResource
    {
        $this->legacyVehicle($request);

        return $this->detail($this->deliveries->setCrew($delivery, $request->validate([
            'vehicle_id' => ['nullable', 'uuid'],
            'driver_id' => ['required', 'uuid'],
            'helper_id' => ['nullable', 'uuid'],
            ...$this->metaRules(),
        ])));
    }

    public function scan(Request $request, Delivery $delivery): JsonResponse
    {
        $result = $this->deliveries->scan($delivery, $request->validate([
            'phase' => ['required', Rule::in(['load', 'unload'])],
            'code' => ['required', 'string', 'max:200'],
            'stop_id' => ['nullable', 'uuid'],
            ...$this->metaRules(),
        ]));

        return $this->detail($delivery)->additional(['meta' => ['asset_id' => $result['item']->asset_id, 'already' => $result['already']]])->response();
    }

    public function unscan(Request $request, Delivery $delivery): DeliveryResource
    {
        return $this->detail($this->deliveries->unscan($delivery, $request->validate(['asset_id' => ['required', 'uuid'], ...$this->metaRules()])));
    }

    public function report(Request $request, Delivery $delivery): DeliveryResource
    {
        return $this->detail($this->deliveries->report($delivery, $request->validate([
            'type' => ['required', Rule::in(DeliveryEvent::REPORTS)],
            'stop_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
            'receiver_name' => ['nullable', 'string', 'max:150'],
            ...$this->metaRules(),
        ])));
    }

    private function listed(): Builder
    {
        return Delivery::visible(Delivery::query())->with(self::RELATIONS)->withCount([
            'items as total_count' => fn ($q) => $q->whereNotIn('state', [DeliveryItem::NOT_LOADED, DeliveryItem::CANCELLED]),
            'items as loaded_count' => fn ($q) => $q->whereNotNull('loaded_at'),
            'items as unloaded_count' => fn ($q) => $q->where('state', DeliveryItem::UNLOADED),
        ]);
    }

    private function detail(Delivery $delivery): DeliveryResource
    {
        $delivery = $this->listed()->with(['items' => fn ($q) => $q->with(['asset', 'loader', 'unloader'])->orderBy('created_at')->orderBy('id')])
            ->findOrFail($delivery->id);
        $events = DeliveryEvent::query()->where('delivery_id', $delivery->id)->with(['stop.customer', 'asset', 'actor'])
            ->orderByDesc('recorded_at')->orderByDesc('id')->limit(self::MAX_EVENTS)->get()->reverse()->values();
        $delivery->setRelation('events', $events);
        $this->attachLast(new Collection([$delivery]));

        return new DeliveryResource($delivery);
    }

    /**
     * The newest event (positions, sent every few minutes, aside) and the newest known position of
     * each delivery, in two queries.
     *
     * @param  \Illuminate\Support\Collection<int, Delivery>  $deliveries
     */
    private function attachLast($deliveries): void
    {
        $latest = fn (bool $positioned) => DeliveryEvent::query()
            ->whereIn('id', DB::table('delivery_events')->selectRaw('DISTINCT ON (delivery_id) id')
                ->whereIn('delivery_id', $deliveries->pluck('id'))
                ->when($positioned, fn ($q) => $q->whereNotNull('latitude'), fn ($q) => $q->where('type', '!=', 'position'))
                ->orderBy('delivery_id')->orderByDesc('recorded_at')->orderByDesc('id'))
            ->with(['stop.customer', 'asset', 'actor'])->get()->keyBy('delivery_id');
        $events = $latest(false);
        $positions = $latest(true);
        foreach ($deliveries as $delivery) {
            $delivery->setRelation('lastEvent', $events->get($delivery->id));
            $delivery->setRelation('lastPosition', $positions->get($delivery->id));
        }
    }

    /** Phones installed before vehicles became master data send the vehicle as vehicle_asset_id (same id). */
    private function legacyVehicle(Request $request): void
    {
        if ($request->has('vehicle_asset_id') && ! $request->has('vehicle_id')) {
            $request->merge(['vehicle_id' => $request->input('vehicle_asset_id')]);
        }
    }

    /** @return array<string, list<mixed>> */
    private function planRules(): array
    {
        return [
            'planned_date' => ['required', 'date_format:Y-m-d', 'after:1999-12-31'],
            'expected_return_date' => ['nullable', 'date_format:Y-m-d'],
            'vehicle_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'stops' => ['required', 'array', 'min:1', 'max:20'],
            'stops.*.customer_id' => ['required', 'uuid', 'distinct'],
            'stops.*.asset_ids' => ['nullable', 'array', 'max:500'],
            'stops.*.asset_ids.*' => ['uuid'],
        ];
    }

    /** Where and when the phone was (optional: no location permission, no signal). */
    private function metaRules(): array
    {
        return [
            'client_id' => ['nullable', 'uuid'],
            'recorded_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
