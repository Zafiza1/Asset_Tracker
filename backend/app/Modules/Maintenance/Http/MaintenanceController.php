<?php

namespace App\Modules\Maintenance\Http;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Modules\Maintenance\MaintenanceService;
use App\Modules\Maintenance\Models\MaintenanceRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/v1/maintenance — only routed while the module is enabled for the
 * project (`module:maintenance` middleware, see routes.php).
 */
class MaintenanceController extends Controller
{
    use ResolvesProject;

    protected const SORTABLE = ['scheduled_at', 'completed_at', 'created_at', 'status', 'title'];

    public function __construct(protected MaintenanceService $maintenance)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [MaintenanceRecord::class, $project]);

        $request->validate([
            'status' => ['nullable', Rule::in(MaintenanceRecord::STATUSES)],
            'asset_id' => ['nullable', 'string'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $query = MaintenanceRecord::query()->with('asset:id,system_id,serial_number,name');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Assets are addressed by their public system_id.
        if ($systemId = $request->query('asset_id')) {
            $query->whereHas('asset', fn ($q) => $q->where('system_id', $systemId));
        }

        if ($request->boolean('overdue')) {
            $query->where('status', 'scheduled')->where('scheduled_at', '<', now());
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")
                ->orWhere('type', 'ilike', "%{$search}%"));
        }

        $sort = (string) $request->query('sort', 'scheduled_at');
        $column = ltrim($sort, '-');
        if (in_array($column, self::SORTABLE, true)) {
            $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        $records = $query->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => MaintenanceRecordResource::collection($records->items()),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    public function show(MaintenanceRecord $maintenance): JsonResponse
    {
        $this->authorize('view', $maintenance);

        return $this->respond($maintenance->load('asset:id,system_id,serial_number,name'));
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('create', [MaintenanceRecord::class, $project]);

        $data = $request->validate([
            'asset_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['nullable', 'string', 'max:50'],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'metadata' => ['nullable', 'array'],
        ]);

        // Tenant-scoped lookup: an asset from another project is simply not found.
        $asset = Asset::where('system_id', $data['asset_id'])->first();
        if (!$asset) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => ['asset_id' => ['The selected asset does not exist in this project']],
            ], 422);
        }

        $record = $this->maintenance->create($project, $asset, $data, $request->user());

        return $this->respond($record, 'Maintenance scheduled', 201);
    }

    public function update(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        $this->authorize('update', $maintenance);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'string', 'max:50'],
            'scheduled_at' => ['nullable', 'date'],
            // Completion goes through /complete so it is recorded consistently.
            'status' => ['sometimes', Rule::in(['scheduled', 'in_progress', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'metadata' => ['nullable', 'array'],
        ]);

        $record = $this->maintenance->update($maintenance, $data, $request->user());

        return $this->respond($record->load('asset:id,system_id,serial_number,name'), 'Maintenance updated');
    }

    public function complete(Request $request, MaintenanceRecord $maintenance): JsonResponse
    {
        $this->authorize('complete', $maintenance);

        $data = $request->validate([
            'completed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        [$record, $next] = $this->maintenance->complete($maintenance, $data, $request->user());

        return response()->json([
            'success' => true,
            'data' => new MaintenanceRecordResource($record->load('asset:id,system_id,serial_number,name')),
            'next' => $next ? new MaintenanceRecordResource($next) : null,
            'message' => 'Maintenance completed',
        ]);
    }

    protected function respond(MaintenanceRecord $record, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new MaintenanceRecordResource($record),
            'message' => $message,
        ], $status);
    }
}
