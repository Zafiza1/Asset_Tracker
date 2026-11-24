<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Collection;

/**
 * Test scenarios of a flow (docs/factory/features/F-08 §3.5, F08-18..21): one per distinct path
 * (each Kondisi Ya/Tidak, each Persetujuan Disetujui/Ditolak) and one per way the start refuses
 * (preconditions, each rule "Tolak transaksi"), at most MAX_SCENARIOS, each named as a sentence.
 *
 * Every scenario comes with inputs for flow/simulate: approval decisions, field values made to
 * meet the conditions on the path, and a sample asset from the tester's own data scope with
 * which the dry run really takes that path (F08-20). Without such an asset the scenario says so;
 * a path that stops before an end is a problem of the flow. Nothing is written: it is the dry run.
 */
final class FlowScenarios
{
    public const MAX_SCENARIOS = 12;

    /** Paths enumerated at most, before the scenarios are chosen. */
    private const MAX_PATHS = 200;

    /** Sample assets tried per request. */
    private const MAX_CANDIDATES = 100;

    /** Blocked-start scenarios kept at most when paths fill the list. */
    private const MAX_BLOCKED = 4;

    private const FLIP = ['eq' => 'neq', 'neq' => 'eq', 'in' => 'not_in', 'not_in' => 'in', 'gt' => 'lte', 'lte' => 'gt',
        'gte' => 'lt', 'lt' => 'gte', 'empty' => 'not_empty', 'not_empty' => 'empty'];

    public function __construct(
        private readonly FlowEngine $engine,
        private readonly AssetScope $scope,
        private readonly FlowDescriber $describer,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array{scenarios: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function build(TransactionTypeDefinition $type, array $graph): array
    {
        $start = collect($graph['nodes'])->firstWhere('type', 'start');
        if ($start === null) {
            return ['scenarios' => [], 'total' => 0, 'truncated' => false];
        }
        $paths = [];
        $this->walk($graph, $start['id'], [], [], $paths);
        $blocked = $this->blocked($start);
        $total = count($paths) + count($blocked);

        $keepBlocked = min(count($blocked), self::MAX_BLOCKED);
        $chosen = array_slice($paths, 0, self::MAX_SCENARIOS - $keepBlocked);
        $chosen = [...$chosen, ...array_slice($blocked, 0, self::MAX_SCENARIOS - count($chosen))];

        $fields = $type->fields()->get()->keyBy('key');
        $roles = $this->engine->userRoles($this->tenancy->tenant()->userId());
        $candidates = $this->candidates($type);
        $nodes = collect($graph['nodes'])->keyBy('id');

        return [
            'scenarios' => array_map(fn (array $s) => $this->scenario($type, $graph, $nodes, $s, $fields, $candidates, $roles), $chosen),
            'total' => $total,
            'truncated' => $total > count($chosen),
        ];
    }

    /**
     * Depth-first, the first outcome first (Ya, Disetujui), so the main path leads. A path that
     * comes back to a step it passed is a loop through people (FlowGraph allows those): not listed.
     *
     * @param  list<array{0: string, 1: string|null}>  $steps
     * @param  array<string, true>  $seen
     * @param  list<array<string, mixed>>  $paths
     */
    private function walk(array $graph, ?string $id, array $steps, array $seen, array &$paths): void
    {
        if (count($paths) >= self::MAX_PATHS) {
            return;
        }
        $node = $id === null ? null : FlowGraph::node($graph, $id);
        if ($node === null) {
            $paths[] = ['kind' => 'path', 'steps' => $steps, 'end' => null];

            return;
        }
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $outcomes = match ($node['type']) {
            'condition' => ['yes', 'no'],
            'approval' => ['approved', 'rejected'],
            'end' => [],
            default => [null],
        };
        if ($outcomes === []) {
            $paths[] = ['kind' => 'path', 'steps' => [...$steps, [$id, null]], 'end' => $id];

            return;
        }
        foreach ($outcomes as $outcome) {
            $this->walk($graph, FlowGraph::next($graph, $id, $outcome), [...$steps, [$id, $outcome]], $seen, $paths);
        }
    }

    /**
     * The start refusing: its preconditions failing, and each rejection (named rule) holding.
     *
     * @param  array<string, mixed>  $start
     * @return list<array<string, mixed>>
     */
    private function blocked(array $start): array
    {
        $config = $start['config'] ?? [];
        $scenarios = [];
        if (($config['preconditions']['rules'] ?? []) !== []) {
            $scenarios[] = ['kind' => 'blocked', 'steps' => [[$start['id'], 'blocked']], 'end' => null, 'rejection' => null];
        }
        foreach (array_values((array) ($config['rejections'] ?? [])) as $i => $rejection) {
            if (is_array($rejection) && ($rejection['rules'] ?? []) !== []) {
                $scenarios[] = ['kind' => 'blocked', 'steps' => [[$start['id'], 'blocked']], 'end' => null, 'rejection' => $i];
            }
        }

        return $scenarios;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $scenario
     * @param  Collection<string, TransactionTypeField>  $fields
     * @param  Collection<int, Asset>  $candidates
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function scenario(TransactionTypeDefinition $type, array $graph, Collection $nodes, array $scenario, Collection $fields, Collection $candidates, array $roles): array
    {
        $start = $nodes->firstWhere('type', 'start');
        $startConfig = $start['config'] ?? [];
        $rejections = array_values(array_filter((array) ($startConfig['rejections'] ?? []), 'is_array'));
        $pre = $startConfig['preconditions'] ?? [];
        $blocked = $scenario['kind'] === 'blocked';
        $rejection = $blocked && $scenario['rejection'] !== null ? $rejections[$scenario['rejection']] : null;

        // What must hold: [conditions, match, wanted]. The start's own first, then the path's.
        $wanted = [[(array) ($pre['rules'] ?? []), $pre['match'] ?? 'all', ! ($blocked && $rejection === null)]];
        foreach ($rejections as $i => $r) {
            if (! $blocked || $rejection !== null) {
                $wanted[] = [(array) $r['rules'], $r['match'] ?? 'all', $blocked && $i === $scenario['rejection']];
            }
            if ($blocked && $i === $scenario['rejection']) {
                break;
            }
        }
        $decisions = [];
        foreach ($scenario['steps'] as [$id, $outcome]) {
            $node = $nodes[$id];
            if ($node['type'] === 'condition') {
                $wanted[] = [(array) ($node['config']['rules'] ?? []), $node['config']['match'] ?? 'all', $outcome === 'yes'];
            } elseif ($node['type'] === 'approval') {
                $decisions[$id] = $outcome;
            }
        }
        $values = $this->values($wanted, $fields);

        $expected = array_map(fn ($s) => [$s[0], $s[1]], $scenario['steps']);
        $found = null;
        if ($scenario['end'] !== null || $blocked) {
            foreach ($candidates->isEmpty() ? [null] : $candidates as $asset) {
                $assets = $asset === null ? new Collection : new Collection([$asset]);
                $path = $this->engine->simulateWith($graph, $assets, $values, $decisions, $roles);
                $taken = array_map(fn ($p) => [$p['node_id'], $p['outcome']], $path);
                $sameRule = ! $blocked || ($path[0]['rule_id'] ?? null) === ($rejection['rule_id'] ?? null);
                if ($taken === $expected && $sameRule) {
                    $found = ['asset' => $asset, 'path' => $path];
                    break;
                }
            }
        }

        $end = $scenario['end'] === null ? null : $nodes[$scenario['end']];
        $status = match (true) {
            ! $blocked && $end === null => 'problem',
            $found === null => 'no_sample',
            default => 'ready',
        };

        return [
            'id' => substr(sha1(json_encode([$expected, $rejection['rule_id'] ?? null])), 0, 12),
            'kind' => $scenario['kind'],
            'name' => $this->name($type, $nodes, $scenario, $end, $rejection, $pre, $startConfig),
            'steps' => array_map(fn ($s) => ['node_id' => $s[0], 'label' => $nodes[$s[0]]['label'] ?? $s[0], 'type' => $nodes[$s[0]]['type'], 'outcome' => $s[1]], $scenario['steps']),
            'end' => $end === null ? null : ['node_id' => $end['id'], 'label' => $end['label'] ?? $end['id'], 'result' => $end['config']['result'] ?? 'completed'],
            'message' => $blocked ? ($rejection['message'] ?? $startConfig['message'] ?? null) : null,
            'rule_id' => $rejection['rule_id'] ?? null,
            'inputs' => [
                'asset_ids' => $found !== null && $found['asset'] !== null ? [$found['asset']->id] : [],
                'field_values' => (object) $values,
                'decisions' => (object) $decisions,
            ],
            'status' => $status,
            'note' => match ($status) {
                'problem' => 'Jalur ini berhenti sebelum langkah Selesai. Perbaiki alurnya.',
                'no_sample' => $candidates->isEmpty()
                    ? 'Belum ada aset dalam cakupan data Anda untuk menguji jalur ini.'
                    : 'Tidak ada aset dalam cakupan data Anda yang membawa transaksi ke jalur ini; jalankan dengan aset sendiri (Skenario sendiri).',
                default => null,
            },
        ];
    }

    /**
     * "Nilai perolehan lebih dari Rp 10.000.000, disetujui Supervisor yang membawahi aset → Selesai".
     *
     * @param  Collection<string, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>|null  $end
     * @param  array<string, mixed>|null  $rejection
     * @param  array<string, mixed>  $pre
     * @param  array<string, mixed>  $startConfig
     */
    private function name(TransactionTypeDefinition $type, Collection $nodes, array $scenario, ?array $end, ?array $rejection, array $pre, array $startConfig): string
    {
        if ($scenario['kind'] === 'blocked') {
            $message = trim((string) ($rejection['message'] ?? $startConfig['message'] ?? ''));

            return ($rejection === null ? 'Prasyarat tidak terpenuhi' : 'Ditolak saat dicatat').($message === '' ? '' : ": \"{$message}\"");
        }
        $parts = [];
        foreach ($scenario['steps'] as [$id, $outcome]) {
            if (in_array($nodes[$id]['type'], ['condition', 'approval'], true)) {
                $parts[] = $this->describer->branch($type, $nodes[$id], $outcome);
            }
        }
        $sentence = $parts === [] ? 'Jalur utama' : implode(', ', $parts);
        $sentence = mb_strtoupper(mb_substr($sentence, 0, 1)).mb_substr($sentence, 1);
        $ending = $end === null ? 'berhenti sebelum Selesai' : ($end['label'] ?? 'Selesai');

        return "{$sentence} → {$ending}";
    }

    /**
     * Field values that make each condition list come out as wanted (all true for "dan", the first
     * for "atau"; the other way round when it must fail). Data that is not a field (the asset,
     * the recorder) is left to the sample asset.
     *
     * @param  list<array{0: list<array<string, mixed>>, 1: string, 2: bool}>  $wanted
     * @param  Collection<string, TransactionTypeField>  $fields
     * @return array<string, mixed>
     */
    private function values(array $wanted, Collection $fields): array
    {
        $values = [];
        foreach ($wanted as [$rules, $match, $want]) {
            $rules = array_values(array_filter($rules, 'is_array'));
            if ($rules === []) {
                continue;
            }
            // all + true / any + false: every rule; all + false / any + true: the first decides.
            $every = ($match === 'any') !== $want;
            foreach ($every ? $rules : [$rules[0]] as $rule) {
                $subject = (string) ($rule['subject'] ?? '');
                if (! str_starts_with($subject, 'field.') || ! isset($fields[substr($subject, 6)])) {
                    continue;
                }
                $field = $fields[substr($subject, 6)];
                $values[$field->key] = $this->sample($field, $want ? (string) $rule['op'] : (self::FLIP[$rule['op']] ?? (string) $rule['op']), $rule['value'] ?? null);
            }
        }

        return $values;
    }

    private function sample(TransactionTypeField $field, string $op, mixed $expected): mixed
    {
        $list = array_values(is_array($expected) ? $expected : [$expected]);
        $number = is_numeric($list[0] ?? null) ? (float) $list[0] : 0.0;
        $multi = $field->data_type === 'multiselect';

        return match ($op) {
            'eq', 'in' => $multi ? [$list[0]] : $list[0],
            'neq', 'not_in' => $this->other($field, $list),
            'gt' => $number + 1,
            'gte', 'lte' => $number,
            'lt' => $number - 1,
            'empty' => null,
            default => $this->any($field),
        };
    }

    /** A value of the field that is none of $list. */
    private function other(TransactionTypeField $field, array $list): mixed
    {
        $strings = array_map(fn ($v) => is_bool($v) ? $v : (string) $v, $list);
        $option = collect($field->settings['options'] ?? [])->first(fn ($o) => ! in_array((string) $o['value'], $strings, true));

        return match ($field->data_type) {
            'select' => $option['value'] ?? null,
            'multiselect' => $option === null ? [] : [$option['value']],
            'boolean' => ! in_array(true, $list, true),
            'number', 'money' => max(array_map('floatval', array_filter($list, 'is_numeric')) ?: [0]) + 1,
            default => 'Contoh lain',
        };
    }

    /** Any filled-in value of the field. */
    private function any(TransactionTypeField $field): mixed
    {
        $option = $field->settings['options'][0]['value'] ?? null;

        return match ($field->data_type) {
            'number', 'money' => 1,
            'date' => now()->toDateString(),
            'datetime' => now()->toIso8601String(),
            'select' => $option,
            'multiselect' => $option === null ? [] : [$option],
            'boolean' => true,
            'user' => $this->tenancy->tenant()->userId(),
            'counterparty' => ['name' => 'Contoh', 'contact' => null],
            default => 'Contoh',
        };
    }

    /**
     * Assets the tester may see and the type applies to (its categories), not archived.
     *
     * @return Collection<int, Asset>
     */
    private function candidates(TransactionTypeDefinition $type): Collection
    {
        $type->loadMissing('categories:id');

        return $this->scope->apply(Asset::query())->with('category:id,path')->orderBy('created_at')->orderBy('id')
            ->limit(self::MAX_CANDIDATES)->get()
            ->filter(function (Asset $asset) use ($type) {
                if ($asset->isArchived()) {
                    return false;
                }
                try {
                    AssetTransactionService::assertCategoryAllowed($asset, $type);

                    return true;
                } catch (ApiException) {
                    return false;
                }
            })->values()->toBase();
    }
}
