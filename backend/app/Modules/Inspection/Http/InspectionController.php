<?php

namespace App\Modules\Inspection\Http;

use App\Http\Controllers\Concerns\ResolvesProject;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Modules\Inspection\InspectionService;
use App\Modules\Inspection\Models\Inspection;
use App\Modules\Inspection\Models\InspectionChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/v1/inspections — only routed while the module is enabled for the
 * project (`module:inspection` middleware, see routes.php).
 */
class InspectionController extends Controller
{
    use ResolvesProject;

    protected const SORTABLE = ['scheduled_at', 'performed_at', 'next_due_at', 'created_at', 'status', 'result'];

    protected const DETAIL = ['asset:id,system_id,serial_number,name,asset_type', 'checklist'];

    public function __construct(protected InspectionService $inspections)
    {
    }

    // ---- Inspections ------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Inspection::class, $project]);

        $request->validate([
            'status' => ['nullable', Rule::in(Inspection::STATUSES)],
            'result' => ['nullable', Rule::in(Inspection::RESULTS)],
            'asset_id' => ['nullable', 'string'],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $query = Inspection::query()->with('asset:id,system_id,serial_number,name,asset_type', 'checklist:id,name');

        foreach (['status', 'result'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($systemId = $request->query('asset_id')) {
            $query->whereHas('asset', fn ($q) => $q->where('system_id', $systemId));
        }

        if ($request->boolean('overdue')) {
            $query->where('status', 'scheduled')->where('scheduled_at', '<', now());
        }

        $sort = (string) $request->query('sort', '-created_at');
        $column = ltrim($sort, '-');
        if (in_array($column, self::SORTABLE, true)) {
            $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        $inspections = $query->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => InspectionResource::collection($inspections->items()),
            'meta' => [
                'current_page' => $inspections->currentPage(),
                'per_page' => $inspections->perPage(),
                'total' => $inspections->total(),
                'last_page' => $inspections->lastPage(),
            ],
        ]);
    }

    public function show(Inspection $inspection): JsonResponse
    {
        $this->authorize('view', $inspection);

        return $this->respond($inspection);
    }

    /**
     * Schedule an inspection — or, with `answers`/`result`, record one done
     * on the spot.
     */
    public function store(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('create', [Inspection::class, $project]);

        $data = $request->validate([
            'asset_id' => ['required', 'string'],
            'checklist_id' => ['nullable', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            ...$this->recordRules(),
        ]);

        $asset = Asset::where('system_id', $data['asset_id'])->first();
        if (!$asset) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => ['asset_id' => ['The selected asset does not exist in this project']],
            ], 422);
        }

        $inspection = $request->has('answers') || $request->has('result')
            ? $this->inspections->perform($project, $asset, $data['answers'] ?? [], $data, $request->user())
            : $this->inspections->schedule($project, $asset, $data, $request->user());

        return $this->respond($inspection, $inspection->status === 'completed' ? 'Inspection recorded' : 'Inspection scheduled', 201);
    }

    public function update(Request $request, Inspection $inspection): JsonResponse
    {
        $this->authorize('update', $inspection);

        $data = $request->validate([
            'checklist_id' => ['nullable', 'integer'],
            'scheduled_at' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            // Results are recorded through /record.
            'status' => ['sometimes', Rule::in(['cancelled'])],
        ]);

        return $this->respond($this->inspections->update($inspection, $data, $request->user()), 'Inspection updated');
    }

    public function record(Request $request, Inspection $inspection): JsonResponse
    {
        $this->authorize('record', $inspection);

        $data = $request->validate($this->recordRules());

        return $this->respond($this->inspections->record($inspection, $data['answers'] ?? [], $data, $request->user()), 'Inspection recorded');
    }

    // ---- Checklists -------------------------------------------------------

    public function checklists(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('viewAny', [Inspection::class, $project]);

        $query = InspectionChecklist::query()->orderBy('name');

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($assetType = $request->query('asset_type')) {
            $query->where(fn ($q) => $q->whereNull('asset_type')->orWhere('asset_type', $assetType));
        }

        return response()->json(['success' => true, 'data' => ChecklistResource::collection($query->get())]);
    }

    public function storeChecklist(Request $request): JsonResponse
    {
        $project = $this->resolveProject();
        $this->authorize('manageChecklists', [Inspection::class, $project]);

        $checklist = $this->inspections->createChecklist($project, $request->validate($this->checklistRules(true)), $request->user());

        return response()->json(['success' => true, 'data' => new ChecklistResource($checklist), 'message' => 'Checklist created'], 201);
    }

    public function updateChecklist(Request $request, InspectionChecklist $checklist): JsonResponse
    {
        $this->authorize('manageChecklists', [Inspection::class, $checklist->project]);

        $checklist = $this->inspections->updateChecklist($checklist, $request->validate($this->checklistRules(false)), $request->user());

        return response()->json(['success' => true, 'data' => new ChecklistResource($checklist), 'message' => 'Checklist updated']);
    }

    protected function recordRules(): array
    {
        return [
            'answers' => ['nullable', 'array'],
            'result' => ['nullable', Rule::in(Inspection::RESULTS)],
            'performed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function checklistRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'asset_type' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'items' => [$required, 'array', 'min:1', 'max:100'],
            'items.*.key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.type' => ['required', Rule::in(InspectionChecklist::ITEM_TYPES)],
            'items.*.required' => ['sometimes', 'boolean'],
        ];
    }

    protected function respond(Inspection $inspection, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new InspectionResource($inspection->fresh(self::DETAIL)),
            'message' => $message,
        ], $status);
    }
}
