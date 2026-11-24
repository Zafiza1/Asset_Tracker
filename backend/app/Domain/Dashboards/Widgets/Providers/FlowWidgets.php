<?php

namespace App\Domain\Dashboards\Widgets\Providers;

use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\AssetTransactions\Flows\FlowGraph;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionTask;
use App\Domain\Dashboards\Widgets\WidgetContext;
use App\Domain\Shared\Tenancy\Tenancy;

/**
 * Widgets about work waiting for people: approval requests and the open steps of running
 * organization transactions (F-07). Both respect the member's data scope — a run is listed
 * only when one of its assets is in scope (the same rule as RunAccess::visible).
 */
final class FlowWidgets
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /** Pending approval requests from other members, oldest first (same set as the home card). */
    public function pendingApprovals(WidgetContext $ctx): array
    {
        $query = ApprovalRequest::query()->where('status', ApprovalRequest::PENDING)
            ->where('requested_by', '!=', $this->tenancy->tenant()->userId());

        return [
            'value' => (clone $query)->count(),
            'unit' => 'count',
        ];
    }

    /** Running transactions and the step each is waiting on. */
    public function pendingTransactionSteps(WidgetContext $ctx): array
    {
        $limit = max(1, min(50, $ctx->intConfig('limit') ?: 10));
        $today = $ctx->today()->toDateString();

        // Runs with at least one asset in the member's scope.
        $scopedAssets = $ctx->assets(includeArchived: true)->select('assets.id');
        $runs = TransactionRun::query()->where('state', TransactionRun::RUNNING)
            ->whereHas('assets', fn ($q) => $q->whereIn('assets.id', $scopedAssets))
            ->with(['definition:id,name', 'flowVersion', 'creator:id,name'])
            ->orderBy('created_at')->limit($limit)->get();

        $openTasks = TransactionTask::query()->where('status', TransactionTask::OPEN)
            ->whereIn('run_id', $runs->pluck('id'))->get()->groupBy('run_id');

        return [
            'columns' => [
                ['key' => 'transaction', 'label' => 'Transaksi'],
                ['key' => 'type', 'label' => 'Jenis'],
                ['key' => 'step', 'label' => 'Langkah menunggu'],
                ['key' => 'started_by', 'label' => 'Diajukan oleh'],
                ['key' => 'due_date', 'label' => 'Jatuh tempo'],
                ['key' => 'open_tasks', 'label' => 'Langkah terbuka'],
            ],
            'rows' => $runs->map(function (TransactionRun $run) use ($openTasks, $today) {
                $tasks = $openTasks->get($run->id, collect());
                $current = $run->current_node_id !== null && $run->flowVersion !== null
                    ? FlowGraph::node($run->flowVersion->graph, $run->current_node_id) : null;
                $dueDates = $tasks->pluck('due_date')->filter()->map->toDateString()->sort()->values();

                return [
                    'transaction' => $run->transaction_number,
                    'type' => $run->definition?->name,
                    'step' => $current['label'] ?? $current['type'] ?? null,
                    'started_by' => $run->creator?->name,
                    'due_date' => $dueDates->first(),
                    'overdue' => $dueDates->contains(fn ($d) => $d < $today),
                    'open_tasks' => $tasks->count(),
                ];
            })->all(),
            'count' => TransactionRun::query()->where('state', TransactionRun::RUNNING)
                ->whereHas('assets', fn ($q) => $q->whereIn('assets.id', $scopedAssets))->count(),
        ];
    }
}
