<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\AssetTransactions\Flows\FlowDescriber;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\AssetTransactions\Services\TransactionTypeService;
use App\Domain\AssetTransactions\Templates\TransactionTemplates;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionTypeResource;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The organization's transaction types and their form fields (docs/factory/features/F-07 §3).
 * Reading needs transaction.view (the transaction form lists active types); changing needs
 * transaction_type.manage, held only by Super Admin and Supervisor (D-27).
 */
class TransactionTypeController extends Controller
{
    private const RELATIONS = ['fields', 'categories:id,name', 'closesType:id,code,name', 'activeFlow:id,version_number', 'draftFlow:id,type_definition_id,version_number,status'];

    public function __construct(private readonly TransactionTypeService $types) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(TransactionTypeDefinition::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            // The type list also shows the system types (F-07f); every other picker wants the organization's own.
            'include_system' => ['nullable', 'boolean'],
        ]);
        $query = TransactionTypeDefinition::query()->with(self::RELATIONS)->withCount('transactions')
            ->when(! $request->boolean('include_system'), fn ($q) => $q->organizationOwn())
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when(trim((string) $request->query('search')), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))->orWhere('code', 'ilike', ListQuery::like($term))))
            ->orderBy('name');

        return TransactionTypeResource::collection($query->get())->additional(['meta' => ['field_types' => TransactionFieldTypes::TYPES]]);
    }

    public function show(TransactionTypeDefinition $transactionType): TransactionTypeResource
    {
        return new TransactionTypeResource($transactionType->load(self::RELATIONS)->loadCount('transactions'));
    }

    public function store(Request $request): JsonResponse
    {
        $definition = $this->types->create($request->validate($this->rules(creating: true)));

        return (new TransactionTypeResource($definition->load(self::RELATIONS)->loadCount('transactions')))->response()->setStatusCode(201);
    }

    public function update(Request $request, TransactionTypeDefinition $transactionType): TransactionTypeResource
    {
        $definition = $this->types->update($transactionType, $request->validate($this->rules(creating: false)));

        return new TransactionTypeResource($definition->load(self::RELATIONS)->loadCount('transactions'));
    }

    /** A new draft organization type copied from this one, flow included (F-08 "Salin jenis"). */
    public function duplicate(Request $request, TransactionTypeDefinition $transactionType): JsonResponse
    {
        $data = $request->validate(array_intersect_key($this->rules(creating: true), array_flip(['code', 'name'])));
        $copy = $this->types->duplicate($transactionType, $data['code'], $data['name']);

        return (new TransactionTypeResource($copy->load(self::RELATIONS)->loadCount('transactions')))->response()->setStatusCode(201);
    }

    /**
     * The ready-made templates (F-08c, F08-02): per card the purpose, the number of fields and of
     * approval steps, and "Lihat cara kerja" (the FlowDescriber documentation) without making anything.
     */
    public function templates(FlowDescriber $describer): JsonResponse
    {
        $data = [];
        foreach (TransactionTemplates::all() as $key => $template) {
            if ($template['closes'] !== null) {
                continue;
            }
            $pair = $template['pair'] !== null ? TransactionTemplates::find($template['pair']) : null;
            $data[] = [
                ...$this->templateCard($key, $template, $describer),
                'pair' => $pair === null ? null : $this->templateCard($template['pair'], $pair, $describer),
            ];
        }

        return response()->json(['data' => $data, 'meta' => ['categories' => TransactionTemplates::CATEGORIES]]);
    }

    /** New draft types from a template (F08-03): the type, and its closing pair when the template has one. */
    public function fromTemplate(Request $request): JsonResponse
    {
        $code = ['nullable', 'string', 'regex:/^[A-Za-z]{2,6}$/'];
        $data = $request->validate([
            'template' => ['required', 'string', 'max:40'],
            'code' => $code,
            'name' => ['nullable', 'string', 'max:100'],
            'pair' => ['sometimes', 'array:code,name'],
            'pair.code' => $code,
            'pair.name' => ['nullable', 'string', 'max:100'],
        ]);
        [$type, $pair] = $this->types->fromTemplate($data['template'], $data['code'] ?? null, $data['name'] ?? null,
            $data['pair']['code'] ?? null, $data['pair']['name'] ?? null);

        return (new TransactionTypeResource($type->load(self::RELATIONS)->loadCount('transactions')))
            ->additional(['meta' => ['pair' => $pair === null ? null : new TransactionTypeResource($pair->load(self::RELATIONS)->loadCount('transactions'))]])
            ->response()->setStatusCode(201);
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function templateCard(string $key, array $template, FlowDescriber $describer): array
    {
        $graph = TransactionTemplates::resolveRoles($template['graph']);
        $preview = new TransactionTypeDefinition;
        $preview->forceFill([
            'name' => $template['name'], 'description' => $template['purpose'], 'multi_asset' => $template['multi_asset'],
            'locks_assets' => $template['locks_assets'], 'core_type' => $template['core_type'],
        ]);
        $preview->setRelation('fields', collect($template['fields'])->map(fn (array $f) => new TransactionTypeField($f)));

        return [
            'key' => $key,
            'name' => $template['name'],
            'category' => $template['category'],
            'purpose' => $template['purpose'],
            'code' => $template['code'],
            'icon' => $template['icon'],
            'core_type' => $template['core_type'],
            'field_count' => count($template['fields']),
            'approval_count' => collect($graph['nodes'])->where('type', 'approval')->count(),
            'fields' => array_map(fn (array $f) => [
                'key' => $f['key'], 'label' => $f['label'], 'data_type' => $f['data_type'],
                'data_type_label' => TransactionFieldTypes::TYPES[$f['data_type']], 'is_required' => $f['is_required'],
            ], $template['fields']),
            'graph' => $graph,
            'documentation' => $describer->describe($preview, $graph),
        ];
    }

    public function destroy(TransactionTypeDefinition $transactionType): Response
    {
        $this->types->delete($transactionType);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $fieldRules = TransactionFieldTypes::definitionRules();
        if (! $creating) {
            $fieldRules['fields'] = ['sometimes', ...array_diff($fieldRules['fields'], ['present'])];
        }

        return [
            'code' => [$required, 'string', 'regex:/^[A-Za-z]{2,6}$/'],
            'name' => [$required, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[a-z0-9-]+$/'],
            'multi_asset' => ['sometimes', 'boolean'],
            'locks_assets' => ['sometimes', 'boolean'],
            'closes_type_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(TransactionTypeDefinition::STATUSES)],
            'category_ids' => ['sometimes', 'array', 'max:100'],
            'category_ids.*' => ['uuid', 'distinct:ignore_case'],
            ...$fieldRules,
        ];
    }
}
