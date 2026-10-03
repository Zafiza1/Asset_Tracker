<?php

namespace App\Modules\Rental\Http;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Modules\Customer\Models\Customer;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\RentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * /api/v1/rentals — only routed while the module is enabled for the project
 * (`module:rental` middleware, see routes.php).
 */
class RentalController extends Controller
{
    use ResolvesProject;

    protected const SORTABLE = ['created_at', 'starts_at', 'due_at', 'returned_at', 'status', 'reference'];

    protected const DETAIL = ['customer:id,code,name', 'asset:id,system_id,serial_number,name', 'destination:id,name'];

    public function __construct(protected RentalService $rentals)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Rental::class, $project]);

        $request->validate([
            'status' => ['nullable', Rule::in(Rental::STATUSES)],
            'customer_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'string'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $query = Rental::query()->with(self::DETAIL);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($systemId = $request->query('asset_id')) {
            $query->whereHas('asset', fn ($q) => $q->where('system_id', $systemId));
        }

        if ($request->boolean('overdue')) {
            $query->where('status', 'active')->where('due_at', '<', now());
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('reference', 'ilike', "%{$search}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"))
                ->orWhereHas('asset', fn ($a) => $a->where('serial_number', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")));
        }

        $sort = (string) $request->query('sort', '-created_at');
        $column = ltrim($sort, '-');
        if (in_array($column, self::SORTABLE, true)) {
            $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        $rentals = $query->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => RentalResource::collection($rentals->items()),
            'meta' => [
                'current_page' => $rentals->currentPage(),
                'per_page' => $rentals->perPage(),
                'total' => $rentals->total(),
                'last_page' => $rentals->lastPage(),
            ],
        ]);
    }

    public function show(Rental $rental): JsonResponse
    {
        $this->authorize('view', $rental);

        return $this->respond($rental);
    }

    /** Reserve an asset; with `checkout: true` it is handed over at once. */
    public function store(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('create', [Rental::class, $project]);

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'asset_id' => ['required', 'string'],
            'reference' => ['nullable', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'destination_location_id' => ['nullable', 'integer'],
            'daily_rate' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'late_fee_per_day' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'checkout' => ['sometimes', 'boolean'],
        ]);

        // Tenant-scoped lookups: rows of another project are simply not found.
        $customer = Customer::find($data['customer_id']);
        if (!$customer) {
            return $this->invalid('customer_id', 'The selected customer does not exist in this project');
        }

        $asset = Asset::where('system_id', $data['asset_id'])->first();
        if (!$asset) {
            return $this->invalid('asset_id', 'The selected asset does not exist in this project');
        }

        // Handing over at once needs rental.update as well.
        if (!empty($data['checkout'])) {
            $this->authorize('handOver', [Rental::class, $project]);
        }

        $rental = !empty($data['checkout'])
            ? $this->rentals->createAndCheckout($project, $customer, $asset, $data, $request->user())
            : $this->rentals->create($project, $customer, $asset, $data, $request->user());

        return $this->respond($rental, $rental->status === 'active' ? 'Asset rented out' : 'Rental reserved', 201);
    }

    public function checkout(Request $request, Rental $rental): JsonResponse
    {
        $this->authorize('update', $rental);
        $data = $request->validate(['checked_out_at' => ['nullable', 'date', 'before_or_equal:now']]);

        return $this->respond($this->rentals->checkout($rental, $data, $request->user()), 'Asset rented out');
    }

    public function extend(Request $request, Rental $rental): JsonResponse
    {
        $this->authorize('update', $rental);
        $data = $request->validate(['due_at' => ['required', 'date']]);

        return $this->respond($this->rentals->extend($rental, Carbon::parse($data['due_at']), $request->user()), 'Rental extended');
    }

    public function returnAsset(Request $request, Rental $rental): JsonResponse
    {
        $this->authorize('update', $rental);
        $data = $request->validate([
            'to_location_id' => ['required', 'integer'],
            'returned_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->respond($this->rentals->returnAsset($rental, (int) $data['to_location_id'], $data, $request->user()), 'Asset returned');
    }

    public function cancel(Request $request, Rental $rental): JsonResponse
    {
        $this->authorize('update', $rental);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->respond($this->rentals->cancel($rental, $data['reason'] ?? null, $request->user()), 'Rental cancelled');
    }

    protected function respond(Rental $rental, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new RentalResource($rental->fresh(self::DETAIL)),
            'message' => $message,
        ], $status);
    }

    protected function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => [$field => [$message]]], 422);
    }
}
