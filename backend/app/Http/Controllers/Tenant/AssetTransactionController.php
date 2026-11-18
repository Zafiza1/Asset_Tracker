<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Flows\FlowEngine;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetTransactionResource;
use App\Http\Resources\TransactionRunResource;
use App\Http\Support\ListQuery;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Asset Transactions container: searchable list, detail, manual recording. */
class AssetTransactionController extends Controller
{
    /** incomingTransaction: return status and days outside of temporary outgoing rows. */
    private const RELATIONS = ['asset', 'fromLocation', 'toLocation', 'creator', 'incomingTransaction:id,outgoing_transaction_id,transaction_date', 'typeDefinition.fields'];

    private const MAX_ASSETS = 200;

    public function __construct(
        private readonly AssetTransactionService $transactions,
        private readonly AssetScope $scope,
        private readonly FlowEngine $engine,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate($this->filterRules());
        $query = $this->filtered($request)->with(self::RELATIONS);

        // Newest first by default; a column header asks for another order.
        $request->filled('sort')
            ? ListQuery::sort($query, $request, [
                'number' => 'transaction_number', 'date' => 'transaction_date',
                'asset' => ListQuery::related(new AssetTransaction, 'asset', 'name'),
                'type' => 'type',
                'by' => ListQuery::related(new AssetTransaction, 'creator', 'name'),
            ], '-date')
            : $query->orderByDesc('transaction_date')->orderByDesc('seq');

        return AssetTransactionResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    public function show(AssetTransaction $assetTransaction): AssetTransactionResource
    {
        // Same answer as for another tenant's id when the asset is outside the user's data scope.
        if (! $this->visible()->whereKey($assetTransaction->id)->exists()) {
            throw new ApiException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return new AssetTransactionResource($assetTransaction->load(self::RELATIONS));
    }

    /**
     * `asset_ids` records one transaction covering many assets (answered as a list);
     * `asset_id` records it for a single asset (answered as one object).
     */
    public function store(Request $request): AssetTransactionResource|JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['required_without:asset_ids', 'prohibits:asset_ids', 'uuid'],
            'asset_ids' => ['required_without:asset_id', 'array', 'min:1', 'max:'.self::MAX_ASSETS],
            'asset_ids.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'type' => ['required', Rule::in([...TransactionType::MANUAL, TransactionType::CUSTOM])],
            // Organization's own types (F-07a): the definition and the values of its fields.
            'type_definition_id' => ['required_if:type,'.TransactionType::CUSTOM, 'prohibited_unless:type,'.TransactionType::CUSTOM, 'nullable', 'uuid'],
            'field_values' => ['prohibited_unless:type,'.TransactionType::CUSTOM, 'nullable', 'array'],
            'transaction_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1999-12-31'],
            'to_location_id' => ['nullable', 'uuid'],
            'to_status' => ['nullable', Rule::in(AssetStatus::SELECTABLE)],
            'borrower_name' => ['nullable', 'string', 'max:150'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => [Rule::requiredIf($request->input('type') === TransactionType::NOTE), 'nullable', 'string', 'max:1000'],
            // Outgoing / incoming (rules per nature and status live in the service).
            'outgoing_nature' => ['nullable', Rule::in(OutgoingReason::NATURES)],
            'reason_code' => ['nullable', Rule::in(OutgoingReason::codes())],
            'counterparty_name' => ['nullable', 'string', 'max:150'],
            'counterparty_org' => ['nullable', 'string', 'max:150'],
            'counterparty_contact' => ['nullable', 'string', 'max:150'],
            'document_number' => ['nullable', 'string', 'max:100'],
        ]);

        $single = isset($data['asset_id']);
        $data['asset_ids'] = array_map('strtolower', $single ? [$data['asset_id']] : $data['asset_ids']);

        // A type with a published flow starts a run (F-07b) instead of recording at once.
        if ($data['type'] === TransactionType::CUSTOM && ($run = $this->startRun($data)) !== null) {
            return (new TransactionRunResource($run))->withDetail()->response()->setStatusCode(201);
        }

        $transactions = (new Collection($this->transactions->create($data)))->load(self::RELATIONS);

        return $single
            ? new AssetTransactionResource($transactions->first())
            : AssetTransactionResource::collection($transactions)->response()->setStatusCode(201);
    }

    /**
     * Rows per list tab (Semua / Keluar / Masuk / Belum kembali) under the other list filters,
     * so the counts match what each tab shows.
     */
    public function counts(Request $request): JsonResponse
    {
        $request->validate($this->filterRules());
        $base = $request->except(['type', 'outgoing_nature', 'not_returned', 'page', 'per_page', 'sort']);
        $count = fn (array $tab) => $this->filtered($request->duplicate([...$base, ...$tab]))->count();

        return response()->json(['data' => [
            'all' => $count([]),
            TransactionType::OUTGOING => $count(['type' => TransactionType::OUTGOING]),
            TransactionType::INCOMING => $count(['type' => TransactionType::INCOMING]),
            'not_returned' => $count(['not_returned' => '1']),
        ]]);
    }

    /**
     * The run a 'custom' transaction starts when its type has a published flow; null when the
     * type has none and the transaction is recorded at once (F-07a).
     *
     * @param  array<string, mixed>  $data
     */
    private function startRun(array $data): ?TransactionRun
    {
        $definition = TransactionTypeDefinition::query()->find($data['type_definition_id']);
        $flow = $definition === null ? null : $this->engine->activeFlow($definition);
        if ($definition === null || $flow === null) {
            return null;
        }

        return $this->engine->start($definition, $flow, $data)
            ->load(['definition.fields', 'flowVersion', 'assets', 'creator', 'steps.actor', 'tasks']);
    }

    /** @return array<string, list<mixed>> */
    private function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(TransactionType::ALL)],
            'outgoing_nature' => ['nullable', Rule::in(OutgoingReason::NATURES)],
            // Temporary outgoing rows whose asset has not come back yet (same rule as the report filter).
            'not_returned' => ['nullable', 'boolean'],
            'asset_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /** Visible transactions narrowed by the (validated) list filters. */
    private function filtered(Request $request): Builder
    {
        return $this->visible()
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('transaction_number', 'ilike', ListQuery::like($term))
                ->orWhere('notes', 'ilike', ListQuery::like($term))
                ->orWhere('borrower_name', 'ilike', ListQuery::like($term))
                ->orWhere('counterparty_name', 'ilike', ListQuery::like($term))
                ->orWhere('document_number', 'ilike', ListQuery::like($term))
                ->orWhereHas('asset', fn ($a) => $a->where('name', 'ilike', ListQuery::like($term)))))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('outgoing_nature'), fn ($q, $nature) => $q->where('outgoing_nature', $nature))
            ->when($request->boolean('not_returned'), fn ($q) => $q->where('type', TransactionType::OUTGOING)
                ->where('outgoing_nature', OutgoingReason::TEMPORARY)->whereDoesntHave('incomingTransaction'))
            ->when($request->query('asset_id'), fn ($q, $id) => $q->where('asset_id', $id))
            ->when($request->query('date_from'), fn ($q, $d) => $q->where('transaction_date', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->where('transaction_date', '<=', $d));
    }

    /** Transactions of assets within the user's data scope. */
    private function visible(): Builder
    {
        return AssetTransaction::query()->whereIn('asset_id', $this->scope->apply(Asset::query())->select('id'));
    }
}
