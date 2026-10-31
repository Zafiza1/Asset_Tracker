<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetTransactionResource;
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
    private const RELATIONS = ['asset', 'fromLocation', 'toLocation', 'creator'];

    private const MAX_ASSETS = 200;

    public function __construct(
        private readonly AssetTransactionService $transactions,
        private readonly AssetScope $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(TransactionType::ALL)],
            'asset_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $query = $this->visible()->with(self::RELATIONS)
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('transaction_number', 'ilike', ListQuery::like($term))
                ->orWhere('notes', 'ilike', ListQuery::like($term))
                ->orWhere('borrower_name', 'ilike', ListQuery::like($term))
                ->orWhereHas('asset', fn ($a) => $a->where('name', 'ilike', ListQuery::like($term))
                    ->orWhere('asset_number', 'ilike', ListQuery::like($term)))))
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('asset_id'), fn ($q, $id) => $q->where('asset_id', $id))
            ->when($request->query('date_from'), fn ($q, $d) => $q->where('transaction_date', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->where('transaction_date', '<=', $d))
            ->orderByDesc('transaction_date')->orderByDesc('seq');

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
            'type' => ['required', Rule::in(TransactionType::MANUAL)],
            'transaction_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1999-12-31'],
            'to_location_id' => ['nullable', 'uuid'],
            'to_status' => ['nullable', Rule::in(AssetStatus::SELECTABLE)],
            'borrower_name' => ['nullable', 'string', 'max:150'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => [Rule::requiredIf($request->input('type') === TransactionType::NOTE), 'nullable', 'string', 'max:1000'],
        ]);

        $single = isset($data['asset_id']);
        $data['asset_ids'] = array_map('strtolower', $single ? [$data['asset_id']] : $data['asset_ids']);
        $transactions = (new Collection($this->transactions->create($data)))->load(self::RELATIONS);

        return $single
            ? new AssetTransactionResource($transactions->first())
            : AssetTransactionResource::collection($transactions)->response()->setStatusCode(201);
    }

    /** Transactions of assets within the user's data scope. */
    private function visible(): Builder
    {
        return AssetTransaction::query()->whereIn('asset_id', $this->scope->apply(Asset::query())->select('id'));
    }
}
