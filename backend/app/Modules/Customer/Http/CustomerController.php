<?php

namespace App\Modules\Customer\Http;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Controllers\Controller;
use App\Modules\Customer\CustomerService;
use App\Modules\Customer\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/v1/customers — only routed while the module is enabled for the
 * project (`module:customer` middleware, see routes.php).
 */
class CustomerController extends Controller
{
    use ResolvesProject;

    protected const SORTABLE = ['name', 'code', 'status', 'created_at'];

    public function __construct(protected CustomerService $customers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Customer::class, $project]);

        $request->validate(['status' => ['nullable', Rule::in(Customer::STATUSES)]]);

        $query = Customer::query()->with('location:id,name,type');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%")
                ->orWhere('contact_name', 'ilike', "%{$search}%"));
        }

        $sort = (string) $request->query('sort', 'name');
        $column = ltrim($sort, '-');
        if (in_array($column, self::SORTABLE, true)) {
            $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        $customers = $query->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return $this->respond($customer->load('location:id,name,type'));
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('create', [Customer::class, $project]);

        $customer = $this->customers->create($project, $request->validate($this->rules(true)), $request->user());

        return $this->respond($customer->load('location:id,name,type'), 'Customer created', 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer = $this->customers->update($customer, $request->validate($this->rules(false)), $request->user());

        return $this->respond($customer->load('location:id,name,type'), 'Customer updated');
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $this->customers->delete($customer, $request->user());

        return response()->json(['success' => true, 'data' => null, 'message' => 'Customer deleted']);
    }

    protected function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/'],
            'name' => [$required, 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'location_id' => ['nullable', 'integer'],
            'status' => ['sometimes', Rule::in(Customer::STATUSES)],
            'metadata' => ['nullable', 'array'],
        ];
    }

    protected function respond(Customer $customer, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new CustomerResource($customer),
            'message' => $message,
        ], $status);
    }
}
