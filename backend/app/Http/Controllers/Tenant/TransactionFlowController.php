<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\AssetTransactions\Flows\FlowDescriber;
use App\Domain\AssetTransactions\Flows\FlowEngine;
use App\Domain\AssetTransactions\Flows\FlowGraph;
use App\Domain\AssetTransactions\Flows\FlowScenarios;
use App\Domain\AssetTransactions\Flows\FlowVersionService;
use App\Domain\AssetTransactions\Flows\RuleCompiler;
use App\Domain\AssetTransactions\Models\TransactionFlowVersion;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\Shared\Exceptions\ApiException;
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
        private readonly FlowDescriber $describer,
    ) {}

    public function show(TransactionTypeDefinition $transactionType): JsonResponse
    {
        $all = TransactionFlowVersion::query()->where('type_definition_id', $transactionType->id)
            ->with('publisher:id,name')->orderByDesc('version_number')->get();
        $draft = $all->firstWhere('status', TransactionFlowVersion::DRAFT);
        $active = $all->firstWhere('id', $transactionType->active_flow_version_id);

        return response()->json(['data' => [
            'active' => $active ? $this->version($active) : null,
            'draft' => $draft ? [...$this->version($draft), 'issues' => FlowVersionService::issues($draft, $transactionType)] : null,
            'versions' => $all->map(fn (TransactionFlowVersion $v) => [
                'version_number' => $v->version_number, 'status' => $v->status, 'change_note' => $v->change_note,
                'published_at' => $v->published_at?->toIso8601String(), 'published_by' => $v->publisher?->name,
            ])->values(),
        ]]);
    }

    /**
     * Saves the draft. With `rules` (the Designer, F-08g) the graph is the user's own flow and the
     * rules are compiled into it; without, the graph is kept as drawn on the canvas (F08-39).
     */
    public function update(Request $request, TransactionTypeDefinition $transactionType): JsonResponse
    {
        $data = $request->validate([...FlowGraph::rules(), ...RuleCompiler::requestRules(), 'change_note' => ['nullable', 'string', 'max:500']]);
        $draft = $this->versions->saveDraft($transactionType, $data['graph'], $data['change_note'] ?? null, $request->has('rules') ? ($data['rules'] ?? []) : null);

        return response()->json(['data' => [...$this->version($draft), 'issues' => FlowVersionService::issues($draft, $transactionType)]]);
    }

    /** Checks a diagram (the one sent, or the saved draft) without saving it. */
    public function validateFlow(Request $request, TransactionTypeDefinition $transactionType): JsonResponse
    {
        if (! $request->has('graph')) {
            $draft = $this->versions->draft($transactionType);
            $issues = $draft === null ? FlowGraph::issues(['nodes' => [], 'edges' => []], $transactionType->fields()->get(), $transactionType->id)
                : FlowVersionService::issues($draft, $transactionType);

            return response()->json(['data' => ['valid' => $issues === [], 'issues' => $issues]]);
        }
        $data = $request->validate([...FlowGraph::rules(), ...RuleCompiler::requestRules()]);
        $graph = ['nodes' => $data['graph']['nodes'] ?? [], 'edges' => $data['graph']['edges'] ?? []];
        $fields = $transactionType->fields()->get();
        $issues = isset($data['rules'])
            ? [...RuleCompiler::issues($data['rules'], $graph, $fields), ...FlowGraph::issues(RuleCompiler::compile($graph, $data['rules']), $fields, $transactionType->id)]
            : FlowGraph::issues($graph, $fields, $transactionType->id);

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
            ...($request->has('graph') ? [...FlowGraph::rules(), ...RuleCompiler::requestRules()] : []),
            'asset_ids' => ['present', 'array', 'max:50'],
            'asset_ids.*' => ['uuid'],
            'field_values' => ['nullable', 'array'],
            'decisions' => ['nullable', 'array'],
            'decisions.*' => ['in:approved,rejected'],
        ]);
        // A graph sent with rules is the Designer's flow: tried with the rules compiled into it (F08-36).
        if (isset($data['graph'], $data['rules'])) {
            $data['graph'] = RuleCompiler::compile(['nodes' => $data['graph']['nodes'] ?? [], 'edges' => $data['graph']['edges'] ?? []], $data['rules']);
        }
        $graph = $data['graph'] ?? $this->versions->draft($transactionType)?->graph
            ?? ($transactionType->active_flow_version_id ? TransactionFlowVersion::query()->find($transactionType->active_flow_version_id)?->graph : null)
            ?? ['nodes' => [], 'edges' => []];

        return response()->json(['data' => ['path' => $this->engine->simulate(
            ['nodes' => $graph['nodes'] ?? [], 'edges' => $graph['edges'] ?? []], $data['asset_ids'], $data['field_values'] ?? [], $data['decisions'] ?? [],
        )]]);
    }

    /**
     * Test scenarios of the flow (F-08 §3.5, F-08d): one per path and per way the start refuses,
     * at most 12, each with inputs for `simulate` and a sample asset from the tester's scope.
     * The graph sent (with rules: compiled first), else the draft, else the active version.
     */
    public function scenarios(Request $request, TransactionTypeDefinition $transactionType, FlowScenarios $scenarios): JsonResponse
    {
        $data = $request->validate($request->has('graph') ? [...FlowGraph::rules(), ...RuleCompiler::requestRules()] : []);
        if (isset($data['graph'])) {
            $graph = ['nodes' => $data['graph']['nodes'] ?? [], 'edges' => $data['graph']['edges'] ?? []];
            $graph = isset($data['rules']) ? RuleCompiler::compile($graph, $data['rules']) : $graph;
        } else {
            $graph = $this->versions->draft($transactionType)?->graph
                ?? ($transactionType->active_flow_version_id ? TransactionFlowVersion::query()->find($transactionType->active_flow_version_id)?->graph : null)
                ?? ['nodes' => [], 'edges' => []];
        }
        $built = $scenarios->build($transactionType, $graph);

        return response()->json(['data' => $built['scenarios'], 'meta' => [
            'total' => $built['total'], 'truncated' => $built['truncated'], 'limit' => FlowScenarios::MAX_SCENARIOS,
        ]]);
    }

    /**
     * "Cara kerja transaksi ini" (F-08 §3.6, D-41): the flow as sentences, with what changed from
     * the version before it. Without ?version: the draft being drawn, else the active version;
     * a type without a flow records at once and is described as such.
     */
    public function describe(Request $request, TransactionTypeDefinition $transactionType): JsonResponse
    {
        $number = $request->validate(['version' => ['nullable', 'integer', 'min:1']])['version'] ?? null;
        $versions = TransactionFlowVersion::query()->where('type_definition_id', $transactionType->id);
        $version = $number !== null
            ? ((clone $versions)->where('version_number', $number)->first()
                ?? throw new ApiException('NOT_FOUND', "Versi alur {$number} tidak ditemukan.", 404))
            : ($this->versions->draft($transactionType)
                ?? ($transactionType->active_flow_version_id ? (clone $versions)->find($transactionType->active_flow_version_id) : null));
        $previous = $version === null ? null : (clone $versions)->where('status', TransactionFlowVersion::PUBLISHED)
            ->where('version_number', '<', $version->version_number)->orderByDesc('version_number')->first();

        return response()->json(['data' => [
            'version' => $version === null ? null : ['version_number' => $version->version_number, 'status' => $version->status],
            'previous_version' => $previous?->version_number,
            ...$this->describer->describe($transactionType, $version?->graph, $previous?->graph, $version?->rules ?? [], $previous?->rules),
        ]]);
    }

    /** @return array<string, mixed> */
    private function version(TransactionFlowVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'graph' => $version->graph,
            // F-08g: the named rules, whether the graph is compiled from them, and the flow without the compiled steps (what the Designer edits).
            'rules' => $version->rules,
            'graph_source' => $version->graph_source,
            'base_graph' => RuleCompiler::strip($version->graph),
            'change_note' => $version->change_note,
            'published_at' => $version->published_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }
}
