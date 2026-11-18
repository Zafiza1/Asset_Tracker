<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Services\TransactionTypeService;
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
        ]);
        $query = TransactionTypeDefinition::query()->with(self::RELATIONS)->withCount('transactions')
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
