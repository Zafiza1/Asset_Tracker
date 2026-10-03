<?php

namespace App\Modules\Delivery\Http;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Modules\Customer\Models\Customer;
use App\Modules\Delivery\DeliveryService;
use App\Modules\Delivery\Models\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/v1/deliveries — only routed while the module is enabled for the
 * project (`module:delivery` middleware, see routes.php).
 */
class DeliveryController extends Controller
{
    use ResolvesProject;

    protected const SORTABLE = ['created_at', 'scheduled_at', 'delivered_at', 'status', 'reference'];

    protected const DETAIL = ['customer:id,code,name', 'destination:id,name', 'items.asset:id,system_id,serial_number,name'];

    public function __construct(protected DeliveryService $deliveries)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Delivery::class, $project]);

        $request->validate([
            'status' => ['nullable', Rule::in(Delivery::STATUSES)],
            'customer_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'string'],
        ]);

        $query = Delivery::query()->with('customer:id,code,name', 'destination:id,name')->withCount('items');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        // Deliveries containing an asset (by its public system_id).
        if ($systemId = $request->query('asset_id')) {
            $query->whereHas('items.asset', fn ($q) => $q->where('system_id', $systemId));
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('reference', 'ilike', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%")));
        }

        $sort = (string) $request->query('sort', '-created_at');
        $column = ltrim($sort, '-');
        if (in_array($column, self::SORTABLE, true)) {
            $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        $deliveries = $query->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => DeliveryResource::collection($deliveries->items()),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'per_page' => $deliveries->perPage(),
                'total' => $deliveries->total(),
                'last_page' => $deliveries->lastPage(),
            ],
        ]);
    }

    public function show(Delivery $delivery): JsonResponse
    {
        $this->authorize('view', $delivery);

        return $this->respond($delivery);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('create', [Delivery::class, $project]);

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'asset_ids' => ['required', 'array', 'min:1', 'max:500'],
            'asset_ids.*' => ['required', 'string', 'distinct'],
            'reference' => ['nullable', 'string', 'max:100'],
            'destination_location_id' => ['nullable', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ]);

        // Tenant-scoped lookups: rows of another project are simply not found.
        $customer = Customer::find($data['customer_id']);
        if (!$customer) {
            return $this->invalid('customer_id', 'The selected customer does not exist in this project');
        }

        $assets = Asset::whereIn('system_id', $data['asset_ids'])->get();
        if ($assets->count() !== count($data['asset_ids'])) {
            $missing = array_values(array_diff($data['asset_ids'], $assets->pluck('system_id')->all()));
            return $this->invalid('asset_ids', 'Unknown assets in this project: ' . implode(', ', $missing));
        }

        $delivery = $this->deliveries->create($project, $customer, $assets, $data, $request->user());

        return $this->respond($delivery, 'Delivery created', 201);
    }

    public function update(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorize('update', $delivery);

        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'destination_location_id' => ['nullable', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ]);

        return $this->respond($this->deliveries->update($delivery, $data, $request->user()), 'Delivery updated');
    }

    public function dispatchDelivery(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorize('update', $delivery);
        $data = $request->validate(['via_location_id' => ['nullable', 'integer']]);

        return $this->respond($this->deliveries->dispatch($delivery, $data, $request->user()), 'Delivery dispatched');
    }

    public function deliver(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorize('update', $delivery);
        $data = $request->validate([
            'received_by' => ['nullable', 'string', 'max:255'],
            'delivered_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->respond($this->deliveries->deliver($delivery, $data, $request->user()), 'Delivery completed');
    }

    public function returnAssets(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorize('update', $delivery);
        $data = $request->validate([
            'to_location_id' => ['required', 'integer'],
            'asset_ids' => ['nullable', 'array', 'min:1'],
            'asset_ids.*' => ['required', 'string', 'distinct'],
        ]);

        $delivery = $this->deliveries->returnAssets($delivery, (int) $data['to_location_id'], $data['asset_ids'] ?? null, $request->user());

        return $this->respond($delivery, 'Assets returned');
    }

    public function cancel(Request $request, Delivery $delivery): JsonResponse
    {
        $this->authorize('update', $delivery);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->respond($this->deliveries->cancel($delivery, $data['reason'] ?? null, $request->user()), 'Delivery cancelled');
    }

    protected function respond(Delivery $delivery, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new DeliveryResource($delivery->fresh(self::DETAIL)),
            'message' => $message,
        ], $status);
    }

    protected function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => [$field => [$message]]], 422);
    }
}
