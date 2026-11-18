<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Flows\FlowEngine;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionTask;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionRunResource;
use App\Http\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Transactions going through a flow (F-07 §5): the list (with "waiting for me"), the detail with
 * diagram and history, doing the current step, cancelling. A run is visible when one of its
 * assets is in the user's data scope, or when the user started it or has its open task.
 */
class TransactionRunController extends Controller
{
    private const DETAIL = ['definition.fields', 'flowVersion', 'assets:id,name', 'creator:id,name', 'steps.actor:id,name', 'tasks'];

    public function __construct(
        private readonly FlowEngine $engine,
        private readonly Tenancy $tenancy,
        private readonly AssetScope $scope,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'state' => ['nullable', Rule::in(TransactionRun::STATES)],
            'assigned_to_me' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
            'type_definition_id' => ['nullable', 'uuid'],
        ]);
        $userId = $this->tenancy->tenant()->userId();
        $query = $this->visible()->with(['definition:id,code,name', 'flowVersion', 'assets:id,name', 'creator:id,name'])
            ->when($request->query('state'), fn ($q, $state) => $q->where('state', $state))
            ->when($request->boolean('mine'), fn ($q) => $q->where('created_by', $userId))
            ->when($request->boolean('assigned_to_me'), fn ($q) => $q->whereIn('id', $this->myOpenTasks()->select('run_id')))
            ->when($request->query('type_definition_id'), fn ($q, $id) => $q->where('type_definition_id', $id))
            ->orderByDesc('created_at');

        return TransactionRunResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    public function show(TransactionRun $transactionRun): TransactionRunResource
    {
        return (new TransactionRunResource($this->visibleOrFail($transactionRun)->load(self::DETAIL)))->withDetail();
    }

    public function complete(Request $request, TransactionRun $transactionRun, TransactionTask $task): TransactionRunResource
    {
        $data = $request->validate([
            'decision' => ['nullable', Rule::in(['approved', 'rejected'])],
            'field_values' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $run = $this->engine->completeTask($this->visibleOrFail($transactionRun), $task, $data);

        return (new TransactionRunResource($run->load(self::DETAIL)))->withDetail();
    }

    public function cancel(Request $request, TransactionRun $transactionRun): TransactionRunResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $run = $this->engine->cancel($this->visibleOrFail($transactionRun), $data['note'] ?? null);

        return (new TransactionRunResource($run->load(self::DETAIL)))->withDetail();
    }

    private function visible(): Builder
    {
        $userId = $this->tenancy->tenant()->userId();

        return TransactionRun::query()->where(fn ($w) => $w
            ->whereHas('assets', fn ($a) => $a->whereIn('assets.id', $this->scope->apply(Asset::query(), 'assets.location_id')->select('assets.id')))
            ->orWhere('created_by', $userId)
            ->orWhereIn('id', $this->myOpenTasks()->select('run_id')));
    }

    private function myOpenTasks(): Builder
    {
        $userId = $this->tenancy->tenant()->userId();

        return TransactionTask::query()->openFor($userId, $this->engine->userRoles($userId));
    }

    private function visibleOrFail(TransactionRun $run): TransactionRun
    {
        if (! $this->visible()->whereKey($run->id)->exists()) {
            throw new ApiException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return $run;
    }
}
