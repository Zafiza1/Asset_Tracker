<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\AssetTransactions\Models\TransactionTypeField;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Named rules of a transaction type (docs/factory/features/F-08 §3.8, D-46), turned into flow
 * steps so the F-07b engine runs them like any other step:
 *
 * - reject: a rejection of the start step (`config.rejections`); the transaction is refused
 *   with the rule's message when its conditions hold, before anything is recorded;
 * - approval: a Kondisi (left out when the rule always applies) and a Persetujuan, after the
 *   flow's own approvals and before its first step that does work (isi data, aksi, tunggu, or
 *   Selesai), in priority order; a refusal ends the run as Ditolak;
 * - skip_approval: a Kondisi in front of the named approval step that goes past it.
 *
 * Compiled steps carry `config.rule_id`, so compiling again first strips them (strip()) and the
 * steps the user drew are kept as they were. Priority 1 comes first. Of two active rules with the
 * same conditions and different outcomes only the higher priority applies (F08-38).
 */
final class RuleCompiler
{
    public const MAX_RULES = 20;

    public const KINDS = ['reject', 'approval', 'skip_approval'];

    /** The end step refused rule approvals lead to. */
    private const REJECTED_END = 'r_ditolak';

    /**
     * Shape of `rules` in a request; meaning is checked by issues().
     *
     * @return array<string, mixed>
     */
    public static function requestRules(string $prefix = 'rules'): array
    {
        return [
            $prefix => ['sometimes', 'array', 'max:'.self::MAX_RULES],
            "{$prefix}.*" => ['array:id,name,description,priority,active,match,assets,conditions,outcome'],
            "{$prefix}.*.id" => ['required', 'string', 'regex:/^[a-z0-9]{1,12}$/', 'distinct'],
            "{$prefix}.*.name" => ['required', 'string', 'max:100'],
            "{$prefix}.*.description" => ['nullable', 'string', 'max:500'],
            "{$prefix}.*.priority" => ['required', 'integer', 'min:1', 'max:100'],
            "{$prefix}.*.active" => ['required', 'boolean'],
            "{$prefix}.*.match" => ['sometimes', Rule::in(['all', 'any'])],
            "{$prefix}.*.assets" => ['sometimes', Rule::in(['any', 'all', 'total'])],
            "{$prefix}.*.conditions" => ['present', 'array', 'max:10'],
            "{$prefix}.*.conditions.*" => ['array:subject,op,value'],
            "{$prefix}.*.conditions.*.subject" => ['required', 'string', 'max:60'],
            "{$prefix}.*.conditions.*.op" => ['required', Rule::in(FlowGraph::OPERATORS)],
            "{$prefix}.*.outcome" => ['required', 'array:kind,message,approver,min_approvals,step'],
            "{$prefix}.*.outcome.kind" => ['required', Rule::in(self::KINDS)],
            "{$prefix}.*.outcome.message" => ['nullable', 'string', 'max:'.FlowActions::MAX_MESSAGE],
            "{$prefix}.*.outcome.approver" => ['sometimes', 'array:type,id,field'],
            "{$prefix}.*.outcome.min_approvals" => ['sometimes', 'integer'],
            "{$prefix}.*.outcome.step" => ['sometimes', 'string', 'max:40'],
        ];
    }

    /**
     * Rules sorted as they apply: priority, then the order given.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public static function ordered(array $rules): array
    {
        $indexed = array_map(null, array_values($rules), array_keys(array_values($rules)));
        usort($indexed, fn ($a, $b) => [(int) ($a[0]['priority'] ?? 100), $a[1]] <=> [(int) ($b[0]['priority'] ?? 100), $b[1]]);

        return array_column($indexed, 0);
    }

    /**
     * Active rules overruled by a higher-priority rule with the same conditions and another
     * outcome (F08-38): rule id => id of the rule that applies instead.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, string>
     */
    public static function overruled(array $rules): array
    {
        $seen = [];
        $overruled = [];
        foreach (self::ordered($rules) as $rule) {
            if (! ($rule['active'] ?? false)) {
                continue;
            }
            $signature = self::signature($rule);
            foreach ($seen[$signature] ?? [] as [$id, $kind]) {
                if ($kind !== $rule['outcome']['kind']) {
                    $overruled[$rule['id']] = $id;

                    continue 2;
                }
            }
            $seen[$signature][] = [$rule['id'], $rule['outcome']['kind']];
        }

        return $overruled;
    }

    /**
     * Problems with the rules themselves; the compiled steps are also checked by FlowGraph::issues.
     *
     * @param  list<array<string, mixed>>  $rules
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  Collection<int, TransactionTypeField>  $fields
     * @return list<array{code: string, message: string, node_id: null, rule_id: string}>
     */
    public static function issues(array $rules, array $graph, Collection $fields): array
    {
        $base = self::strip($graph);
        $approvals = collect($base['nodes'])->where('type', 'approval')->keyBy('id');
        $issues = [];
        foreach ($rules as $rule) {
            $add = function (string $message) use (&$issues, $rule) {
                $issues[] = ['code' => 'RULE', 'message' => 'Rule "'.($rule['name'] ?? $rule['id']).'": '.$message, 'node_id' => null, 'rule_id' => (string) $rule['id']];
            };
            $kind = $rule['outcome']['kind'] ?? null;
            $conditions = (array) ($rule['conditions'] ?? []);
            foreach (array_unique(FlowGraph::conditionIssues($conditions, $fields)) as $message) {
                $add($message);
            }
            if ($conditions === [] && $kind !== 'approval') {
                $add('isi minimal satu kondisi.');
            }
            if ($kind === 'reject' && trim((string) ($rule['outcome']['message'] ?? '')) === '') {
                $add('tulis pesan penolakan untuk pencatat.');
            }
            if ($kind === 'skip_approval' && ! isset($approvals[$rule['outcome']['step'] ?? ''])) {
                $add('pilih langkah persetujuan yang dilewati.');
            }
        }

        return $issues;
    }

    /**
     * The flow with the active rules as steps (replacing any compiled before).
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  list<array<string, mixed>>  $rules
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function compile(array $graph, array $rules): array
    {
        $graph = self::strip($graph);
        $overruled = self::overruled($rules);
        $active = array_values(array_filter(self::ordered($rules), fn ($r) => ($r['active'] ?? false) && ! isset($overruled[$r['id']])));
        $nodes = collect($graph['nodes'])->keyBy('id')->all();
        $edges = $graph['edges'];
        $start = collect($nodes)->firstWhere('type', 'start');
        if ($start === null) {
            return $graph;
        }

        $rejections = array_values(array_map(fn ($r) => [
            'rule_id' => $r['id'], 'match' => $r['match'] ?? 'all', 'assets' => $r['assets'] ?? 'any',
            'rules' => array_values((array) $r['conditions']), 'message' => trim((string) ($r['outcome']['message'] ?? '')),
        ], array_filter($active, fn ($r) => $r['outcome']['kind'] === 'reject' && $r['conditions'] !== [])));
        if ($rejections !== []) {
            $nodes[$start['id']]['config']['rejections'] = $rejections;
        }

        $approvalRules = array_values(array_filter($active, fn ($r) => $r['outcome']['kind'] === 'approval'));
        if ($approvalRules !== []) {
            [$exit, $from] = self::insertionPoint($nodes, $edges, $start['id']);
            if ($exit !== null) {
                $at = $nodes[$exit]['position'] ?? ['x' => 0, 'y' => 0];
                $nodes[self::REJECTED_END] = self::node(self::REJECTED_END, 'end', 'Ditolak', ['result' => 'rejected'], $at, 1, 320);
                $head = $exit;
                foreach (array_reverse($approvalRules, true) as $i => $rule) {
                    $after = $head;
                    $approval = "ra_{$rule['id']}";
                    $nodes[$approval] = self::node($approval, 'approval', $rule['name'], [
                        'approver' => $rule['outcome']['approver'] ?? [], 'min_approvals' => $rule['outcome']['min_approvals'] ?? 1,
                    ], $at, $i, 160, $rule['id']);
                    $edges[] = self::edge($approval, $after, 'approved');
                    $edges[] = self::edge($approval, self::REJECTED_END, 'rejected');
                    $head = $approval;
                    if ($rule['conditions'] !== []) {
                        $condition = "rc_{$rule['id']}";
                        $nodes[$condition] = self::node($condition, 'condition', $rule['name'].'?', [
                            'match' => $rule['match'] ?? 'all', 'assets' => $rule['assets'] ?? 'any', 'rules' => array_values($rule['conditions']),
                        ], $at, $i, -160, $rule['id']);
                        $edges[] = self::edge($condition, $approval, 'yes');
                        $edges[] = self::edge($condition, $after, 'no');
                        $head = $condition;
                    }
                }
                foreach ($edges as $k => $edge) {
                    if ($edge['target'] === $exit && in_array($edge['source'], $from, true)) {
                        $edges[$k]['target'] = $head;
                    }
                }
            }
        }

        // After the approval rules, so a skipped approval still leads to them.
        $skips = collect($active)->filter(fn ($r) => $r['outcome']['kind'] === 'skip_approval' && $r['conditions'] !== []
            && ($nodes[$r['outcome']['step'] ?? ''] ?? null)['type'] === 'approval' && ! isset($nodes[$r['outcome']['step']]['config']['rule_id']))
            ->groupBy(fn ($r) => $r['outcome']['step']);
        foreach ($skips as $step => $group) {
            $past = FlowGraph::next(['nodes' => array_values($nodes), 'edges' => $edges], $step, 'approved');
            if ($past === null) {
                continue;
            }
            $incoming = array_keys(array_filter($edges, fn ($e) => $e['target'] === $step));
            $at = $nodes[$step]['position'] ?? ['x' => 0, 'y' => 0];
            $head = $step;
            foreach ($group->reverse()->values() as $i => $rule) {
                $condition = "rs_{$rule['id']}";
                $nodes[$condition] = self::node($condition, 'condition', $rule['name'].'?', [
                    'match' => $rule['match'] ?? 'all', 'assets' => $rule['assets'] ?? 'any', 'rules' => array_values($rule['conditions']),
                ], $at, $i + 1, -160, $rule['id']);
                $edges[] = self::edge($condition, $past, 'yes');
                $edges[] = self::edge($condition, $head, 'no');
                $head = $condition;
            }
            foreach ($incoming as $k) {
                $edges[$k]['target'] = $head;
            }
        }

        return ['nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }

    /**
     * The flow without compiled rule steps: lines into them go where they would have led when
     * no rule applied (a Kondisi's "Tidak", a Persetujuan's "Disetujui").
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public static function strip(array $graph): array
    {
        $nodes = collect($graph['nodes'])->keyBy('id');
        $compiled = $nodes->filter(fn ($n) => self::isCompiled($n))->keys()->flip()->all();
        if ($compiled === [] && ! $nodes->contains(fn ($n) => isset($n['config']['rejections']))) {
            return $graph;
        }
        $edges = collect($graph['edges']);
        $resolve = function (string $id) use ($compiled, $nodes, $edges): ?string {
            for ($i = 0; isset($compiled[$id]) && $i <= count($compiled); $i++) {
                $outcome = ($nodes[$id]['type'] ?? null) === 'approval' ? 'approved' : 'no';
                $id = $edges->first(fn ($e) => $e['source'] === $id && ($e['outcome'] ?? null) === $outcome)['target'] ?? null;
                if ($id === null) {
                    return null;
                }
            }

            return isset($compiled[$id]) ? null : $id;
        };
        $kept = [];
        foreach ($edges as $edge) {
            if (isset($compiled[$edge['source']])) {
                continue;
            }
            $target = isset($compiled[$edge['target']]) ? $resolve($edge['target']) : $edge['target'];
            if ($target !== null) {
                $kept[] = [...$edge, 'target' => $target];
            }
        }

        return [
            'nodes' => $nodes->reject(fn ($n, $id) => isset($compiled[$id]))->map(function ($n) {
                unset($n['config']['rejections']);

                return $n;
            })->values()->all(),
            'edges' => $kept,
        ];
    }

    /** @param array<string, mixed> $node */
    public static function isCompiled(array $node): bool
    {
        return array_key_exists('rule_id', $node['config'] ?? []);
    }

    /**
     * Where rule approvals go: in front of the first step that does work after the flow's own
     * approvals and conditions, when every way from the start meets the same such step; else
     * right after the start. Returns that step and the steps whose lines into it are redirected.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return array{0: string|null, 1: list<string>}
     */
    private static function insertionPoint(array $nodes, array $edges, string $start): array
    {
        $seen = [$start => true];
        $queue = [$start];
        $frontier = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($edges as $edge) {
                if ($edge['source'] !== $id || ($edge['outcome'] ?? null) === 'rejected') {
                    continue;
                }
                $target = $nodes[$edge['target']] ?? null;
                if ($target === null || ($target['type'] === 'end' && ($target['config']['result'] ?? 'completed') !== 'completed')) {
                    continue;
                }
                if (in_array($target['type'], ['approval', 'condition'], true)) {
                    if (! isset($seen[$target['id']])) {
                        $seen[$target['id']] = true;
                        $queue[] = $target['id'];
                    }
                } else {
                    $frontier[$target['id']] = true;
                }
            }
        }
        if (count($frontier) === 1) {
            return [array_key_first($frontier), array_keys($seen)];
        }
        $first = FlowGraph::next(['nodes' => array_values($nodes), 'edges' => $edges], $start);

        return [$first, [$start]];
    }

    /** @param array<string, mixed> $rule */
    private static function signature(array $rule): string
    {
        $conditions = array_map(fn ($c) => [$c['subject'] ?? null, $c['op'] ?? null, $c['value'] ?? null], (array) ($rule['conditions'] ?? []));
        sort($conditions);

        return json_encode([$rule['match'] ?? 'all', $rule['assets'] ?? 'any', $conditions]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{x: int|float, y: int|float}  $at
     * @return array<string, mixed>
     */
    private static function node(string $id, string $type, string $label, array $config, array $at, int $index, int $dy, string $ruleId = '*'): array
    {
        return ['id' => $id, 'type' => $type, 'label' => mb_substr($label, 0, 100), 'position' => ['x' => $at['x'] - 280 + $index * 40, 'y' => $at['y'] + $dy],
            'config' => [...$config, 'rule_id' => $ruleId]];
    }

    /** @return array{id: string, source: string, target: string, outcome: string|null} */
    private static function edge(string $source, string $target, ?string $outcome = null): array
    {
        return ['id' => mb_substr("re_{$source}_".($outcome ?? 'next'), 0, 40), 'source' => $source, 'target' => $target, 'outcome' => $outcome];
    }
}
