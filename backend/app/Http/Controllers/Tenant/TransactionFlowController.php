<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\AssetTransactions\Flows\FlowEngine;
use App\Domain\AssetTransactions\Flows\FlowGraph;
use App\Domain\AssetTransactions\Flows\FlowVersionService;
use App\Domain\AssetTransactions\Models\TransactionFlowVersion;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The flow of a transaction type (F-07 §4): the active version, the draft being drawn, checks,
 * publishing and the dry run ("Uji alur"). Reading needs transaction.view; drawing, checking,
 * testing and publishing need transaction_flow.manage (D-27).
 */
class TransactionFlowController extends Controller
{
    public function __construct(
        private readonly FlowVersionService $versions,
        private readonly FlowEngine $engine,
    ) {}

    public function show(TransactionTypeDefinition $transactionType): JsonResponse
    {
        $all = TransactionFlowVersion::query()->where('type_definition_id', $transactionType->id)
            ->with('publisher:id,name')->orderByDesc('version_number')->get();
        $draft = $all->firstWhere('status', TransactionFlowVersion::DRAFT);
        $active = $all->firstWhere('id', $transactionType->active_flow_version_id);

        return response()->json(['data' => [
            'active' => $active ? $this->version($active) : null,
            'draft' => $draft ? [...$this->version($draft), 'issues' => FlowGraph::issues($draft->graph, $transactionType->fields()->get())] : null,
            'versions' => $all->map(fn (TransactionFlowVersion $v) => [
                'version_number' => $v->version_number, 'status' => $v->status, 'change_note' => $v->change_note,
                'published_at' => $v->published_at?->toIso8601String(), 'published_by' => $v->publisher?->name,
            ])->values(),
        ]]);
    }

    public function update(Request $request, TransactionTypeDefinition $transactionType): JsonResponse
    {
        $data = $request->validate([...FlowGraph::rules(), 'change_note' => ['nullable', 'string', 'max:500']]);
        $draft = $this->versions->saveDraft($transactionType, $data['graph'], $data['change_note'] ?? null);

        return response()->json(['data' => [...$this->version($draft), 'issues' => FlowGraph::issues($draft->graph, $transactionType->fields()->get())]]);
    }

    /** Checks a diagram (the one sent, or the saved draft) without saving it. */
    public function validateFlow(Request $request, TransactionTypeDefinition $transactionType): JsonResponse
    {
        $graph = $request->has('graph') ? $request->validate(FlowGraph::rules())['graph'] : ($this->versions->draft($transactionType)?->graph ?? ['nodes' => [], 'edges' => []]);
        $issues = FlowGraph::issues(['nodes' => $graph['nodes'] ?? [], 'edges' => $graph['edges'] ?? []], $transactionType->fields()->get());

        return response()->json(['data' => ['valid' => $issues === [], 'issues' => $issues]]);
    }

    public function publish(TransactionTypeDefinition $transactionType): JsonResponse
    {
        return response()->json(['data' => $this->version($this->versions->publish($transactionType))]);
    }

    /** The path a transaction would take with these assets and values; nothing is saved. */
    public function simulate(Request $request, TransactionTypeDefinition $transactionType): JsonResponse
    {
        $data = $request->validate([
            ...($request->has('graph') ? FlowGraph::rules() : []),
            'asset_ids' => ['present', 'array', 'max:50'],
            'asset_ids.*' => ['uuid'],
            'field_values' => ['nullable', 'array'],
            'decisions' => ['nullable', 'array'],
            'decisions.*' => ['in:approved,rejected'],
        ]);
        $graph = $data['graph'] ?? $this->versions->draft($transactionType)?->graph
            ?? ($transactionType->active_flow_version_id ? TransactionFlowVersion::query()->find($transactionType->active_flow_version_id)?->graph : null)
            ?? ['nodes' => [], 'edges' => []];

        return response()->json(['data' => ['path' => $this->engine->simulate(
            ['nodes' => $graph['nodes'] ?? [], 'edges' => $graph['edges'] ?? []], $data['asset_ids'], $data['field_values'] ?? [], $data['decisions'] ?? [],
        )]]);
    }

    /** @return array<string, mixed> */
    private function version(TransactionFlowVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'graph' => $version->graph,
            'change_note' => $version->change_note,
            'published_at' => $version->published_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }
}
