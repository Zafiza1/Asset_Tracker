<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The diagram of a transaction flow (docs/factory/features/F-07 §4): node and edge shapes, and
 * the checks a flow must pass before it is published (§4.3). Conditions use registered subjects
 * and operators only; nothing in a flow is evaluated as code (F-07 §7).
 *
 * Node types: start, input, approval, condition, action, wait, end. Edges carry an outcome on
 * branching nodes: approval → approved/rejected, condition → yes/no; other edges have none.
 */
final class FlowGraph
{
    public const NODE_TYPES = ['start', 'input', 'approval', 'condition', 'action', 'wait', 'end'];

    /** Steps done by people; any loop in a flow must pass one of them. */
    public const HUMAN = ['input', 'approval'];

    public const MAX_NODES = 50;

    public const MAX_EDGES = 100;

    /** Most approvers one approval step may need. */
    public const MAX_APPROVALS = 5;

    public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty'];

    /** Fixed subjects; field.<key> subjects name a field of the type. */
    public const SUBJECTS = ['asset.status', 'asset.condition', 'asset.category', 'asset.location', 'asset.acquisition_cost', 'starter.role'];

    /** Statuses a flow action may set (disposal keeps its own permission and transaction). */
    public const ACTION_STATUSES = [AssetStatus::AVAILABLE, AssetStatus::IN_USE, AssetStatus::UNDER_MAINTENANCE, AssetStatus::LOST];

    /** Outgoing edge outcomes each node type needs. */
    private const OUTCOMES = [
        'start' => [null], 'input' => [null], 'action' => [null], 'wait' => [null],
        'approval' => ['approved', 'rejected'], 'condition' => ['yes', 'no'], 'end' => [],
    ];

    /**
     * Shape of a graph in a request; meaning is checked by issues().
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'graph'): array
    {
        $id = ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/'];

        return [
            $prefix => ['required', 'array:nodes,edges'],
            "{$prefix}.nodes" => ['present', 'array', 'max:'.self::MAX_NODES],
            "{$prefix}.nodes.*" => ['array:id,type,label,position,config'],
            "{$prefix}.nodes.*.id" => [...$id, 'distinct'],
            "{$prefix}.nodes.*.type" => ['required', Rule::in(self::NODE_TYPES)],
            "{$prefix}.nodes.*.label" => ['nullable', 'string', 'max:100'],
            "{$prefix}.nodes.*.position" => ['sometimes', 'array:x,y'],
            "{$prefix}.nodes.*.position.x" => ['numeric', 'between:-100000,100000'],
            "{$prefix}.nodes.*.position.y" => ['numeric', 'between:-100000,100000'],
            "{$prefix}.nodes.*.config" => ['sometimes', 'array'],
            "{$prefix}.edges" => ['present', 'array', 'max:'.self::MAX_EDGES],
            "{$prefix}.edges.*" => ['array:id,source,target,outcome'],
            "{$prefix}.edges.*.id" => [...$id, 'distinct'],
            "{$prefix}.edges.*.source" => $id,
            "{$prefix}.edges.*.target" => $id,
            "{$prefix}.edges.*.outcome" => ['nullable', Rule::in(['approved', 'rejected', 'yes', 'no'])],
        ];
    }

    /**
     * Problems that keep a flow from being published; an empty list means it is valid.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  Collection<int, TransactionTypeField>  $fields
     * @param  string|null  $typeId  the flow's type, to check a wait for its paired (closing) type
     * @return list<array{code: string, message: string, node_id: string|null}>
     */
    public static function issues(array $graph, Collection $fields, ?string $typeId = null): array
    {
        $issues = [];
        $add = function (string $code, string $message, ?string $node = null) use (&$issues) {
            $issues[] = ['code' => $code, 'message' => $message, 'node_id' => $node];
        };
        $nodes = collect($graph['nodes'])->keyBy('id');
        $edges = collect($graph['edges']);
        $label = fn (string $id) => '"'.($nodes[$id]['label'] ?? null ?: $id).'"';
        $systemType = $typeId !== null ? TransactionTypeDefinition::query()->whereKey($typeId)->value('system_type') : null;

        $starts = $nodes->where('type', 'start');
        if ($starts->count() !== 1) {
            $add('START_COUNT', 'Alur harus memiliki tepat satu langkah Mulai.');
        }
        if ($nodes->where('type', 'end')->isEmpty()) {
            $add('NO_END', 'Alur harus memiliki minimal satu langkah Selesai.');
        }

        foreach ($edges as $edge) {
            if (! isset($nodes[$edge['source']], $nodes[$edge['target']])) {
                $add('EDGE_DANGLING', 'Ada garis yang tidak tersambung ke langkah.');
            } elseif ($edge['source'] === $edge['target']) {
                $add('EDGE_SELF', "Langkah {$label($edge['source'])} tidak boleh tersambung ke dirinya sendiri.", $edge['source']);
            }
        }
        $edges = $edges->filter(fn ($e) => isset($nodes[$e['source']], $nodes[$e['target']]) && $e['source'] !== $e['target']);

        foreach ($nodes as $id => $node) {
            $out = $edges->where('source', $id);
            $outcomes = $out->pluck('outcome')->all();
            $expected = self::OUTCOMES[$node['type']];
            sort($outcomes);
            $sorted = $expected;
            sort($sorted);
            if ($outcomes !== $sorted) {
                $add('BRANCHES', self::branchMessage($node['type'], $label($id)), $id);
            }
            if ($node['type'] === 'start' && $edges->where('target', $id)->isNotEmpty()) {
                $add('START_INCOMING', 'Langkah Mulai tidak boleh menerima garis masuk.', $id);
            }
            if ($node['type'] !== 'start' && $edges->where('target', $id)->isEmpty()) {
                $add('UNREACHABLE', "Langkah {$label($id)} tidak terhubung: belum ada garis masuk.", $id);
            }
            foreach (self::configIssues($node, $fields, $typeId, $systemType) as $message) {
                $add('CONFIG', "{$label($id)}: {$message}", $id);
            }
        }

        if ($starts->count() === 1) {
            $start = $starts->keys()->first();
            $reached = self::reach($start, $edges, 'source', 'target');
            foreach ($nodes->keys()->diff($reached) as $id) {
                if ($edges->where('target', $id)->isNotEmpty()) {
                    $add('UNREACHABLE', "Langkah {$label($id)} tidak dapat dicapai dari Mulai.", $id);
                }
            }
        }
        $ends = $nodes->where('type', 'end')->keys();
        $toEnd = $ends->flatMap(fn ($end) => self::reach($end, $edges, 'target', 'source'))->unique();
        foreach ($nodes->keys()->diff($toEnd) as $id) {
            $add('DEAD_END', "Dari langkah {$label($id)} transaksi tidak dapat mencapai Selesai.", $id);
        }

        $automatic = $nodes->reject(fn ($n) => in_array($n['type'], self::HUMAN, true))->keys()->all();
        if (self::hasCycle($automatic, $edges)) {
            $add('AUTOMATIC_LOOP', 'Ada perulangan tanpa langkah Isi data atau Persetujuan; transaksi dapat berputar tanpa akhir.');
        }

        if ($systemType !== null) {
            self::coreIssues($nodes, $edges, $starts->keys()->first(), $label, $add);
        }

        return $issues;
    }

    /** @param array<string, mixed> $node an action step holding the core action (F-07f-2) */
    public static function isCore(array $node): bool
    {
        return ($node['type'] ?? null) === 'action'
            && collect($node['config']['actions'] ?? [])->contains(fn ($a) => is_array($a) && ($a['kind'] ?? null) === FlowActions::CORE);
    }

    /**
     * A system type's flow (F-07f-2) holds its core action once, and no way to a completed end
     * passes it by: the built-in transaction is always recorded when the run completes.
     *
     * @param  Collection<string, array<string, mixed>>  $nodes
     * @param  Collection<int, array<string, mixed>>  $edges
     */
    private static function coreIssues(Collection $nodes, Collection $edges, ?string $start, \Closure $label, \Closure $add): void
    {
        $core = $nodes->filter(fn ($n) => self::isCore($n))->keys();
        if ($core->count() !== 1) {
            $add('CORE_COUNT', 'Alur jenis sistem harus memiliki tepat satu langkah Aksi inti.');

            return;
        }
        if ($start === null) {
            return;
        }
        $coreId = $core->first();
        $around = $edges->reject(fn ($e) => $e['source'] === $coreId || $e['target'] === $coreId);
        foreach (self::reach($start, $around, 'source', 'target') as $id) {
            $node = $nodes[$id];
            if ($node['type'] === 'end' && ($node['config']['result'] ?? 'completed') === 'completed') {
                $add('CORE_BYPASSED', "Transaksi dapat sampai di {$label($id)} tanpa melewati Aksi inti {$label($coreId)}.", $id);
            }
        }
    }

    /**
     * Checks of one node's settings against the type's fields and the organization's roles.
     *
     * @param  array<string, mixed>  $node
     * @param  Collection<int, TransactionTypeField>  $fields
     * @return list<string>
     */
    private static function configIssues(array $node, Collection $fields, ?string $typeId, ?string $systemType = null): array
    {
        $config = $node['config'] ?? [];
        $byKey = $fields->keyBy('key');
        $field = fn (?string $key, ?string $type = null) => $key !== null && isset($byKey[$key]) && ($type === null || $byKey[$key]->data_type === $type);
        $issues = [];

        switch ($node['type']) {
            case 'start':
                foreach ((array) ($config['roles'] ?? []) as $roleId) {
                    if (! is_string($roleId) || ! Role::query()->whereKey($roleId)->exists()) {
                        $issues[] = 'role yang boleh memulai tidak ditemukan.';
                    }
                }
                $issues = [...$issues, ...self::ruleIssues($config['preconditions']['rules'] ?? [], $byKey)];
                break;
            case 'input':
                $assignee = $config['assignee'] ?? ['type' => 'starter'];
                $issues = [...$issues, ...self::assigneeIssues($assignee, $field, false)];
                $keys = (array) ($config['fields'] ?? []);
                if ($keys === []) {
                    $issues[] = 'pilih minimal satu field yang diisi di langkah ini.';
                }
                foreach ($keys as $key) {
                    if (! $field($key)) {
                        $issues[] = "field \"{$key}\" tidak ada di jenis ini.";
                    }
                }
                if (array_diff((array) ($config['required'] ?? []), $keys) !== []) {
                    $issues[] = 'field wajib harus termasuk field yang diisi di langkah ini.';
                }
                break;
            case 'approval':
                $approver = $config['approver'] ?? [];
                $issues = [...$issues, ...self::assigneeIssues($approver, $field, true)];
                $min = $config['min_approvals'] ?? 1;
                if (! is_int($min) || $min < 1 || $min > self::MAX_APPROVALS) {
                    $issues[] = 'jumlah minimal penyetuju 1 sampai '.self::MAX_APPROVALS.'.';
                } elseif ($min > 1 && ($approver['type'] ?? null) === 'user') {
                    $issues[] = 'penyetuju satu pengguna hanya dapat memberi satu persetujuan; pilih role atau atasan lokasi.';
                }
                break;
            case 'condition':
                $rules = $config['rules'] ?? [];
                if ($rules === []) {
                    $issues[] = 'isi minimal satu syarat.';
                }
                if (! in_array($config['match'] ?? 'all', ['all', 'any'], true) || ! in_array($config['assets'] ?? 'any', ['any', 'all', 'total'], true)) {
                    $issues[] = 'pengaturan syarat tidak dikenal.';
                }
                $issues = [...$issues, ...self::ruleIssues($rules, $byKey)];
                break;
            case 'action':
                $actions = $config['actions'] ?? [];
                if ($actions === [] || count($actions) > 5) {
                    $issues[] = 'isi 1 sampai 5 aksi.';
                }
                foreach ($actions as $action) {
                    $action = is_array($action) ? $action : [];
                    if (($action['kind'] ?? null) === FlowActions::CORE) {
                        if ($systemType === null) {
                            $issues[] = 'aksi inti hanya ada pada jenis sistem.';
                        } elseif (count($actions) > 1) {
                            $issues[] = 'aksi inti berdiri sendiri di langkahnya; pindahkan aksi lain ke langkah Aksi tersendiri.';
                        }

                        continue;
                    }
                    $optional = fn (?string $key, string $type) => $key === null || $field($key, $type);
                    $ok = match ($action['kind'] ?? null) {
                        'set_status' => in_array($action['status'] ?? null, self::ACTION_STATUSES, true),
                        'move_to_field_location' => $field($action['field'] ?? null, 'location'),
                        // A fixed option of Kondisi Aset, or a choice field whose options name one.
                        'set_condition' => isset($action['field']) ? $field($action['field'], 'select')
                            : is_string($action['value'] ?? null) && trim($action['value']) !== '',
                        'loan_to_field_user' => $field($action['user_field'] ?? null, 'user') && $optional($action['due_field'] ?? null, 'date'),
                        'return' => $optional($action['location_field'] ?? null, 'location'),
                        'notify' => is_string($action['message'] ?? null) && trim($action['message']) !== ''
                            && mb_strlen($action['message']) <= FlowActions::MAX_MESSAGE
                            && self::assigneeIssues(is_array($action['to'] ?? null) ? $action['to'] : [], $field, false) === [],
                        default => false,
                    };
                    if (! $ok) {
                        $issues[] = 'aksi tidak lengkap atau tidak dikenal.';
                    }
                }
                break;
            case 'wait':
                $until = $config['until'] ?? null;
                if ($until === 'date') {
                    if (! $field($config['field'] ?? null, 'date')) {
                        $issues[] = 'pilih field tanggal yang ditunggu.';
                    }
                } elseif ($until === 'paired') {
                    $closing = $typeId !== null && TransactionTypeDefinition::query()->where('closes_type_id', $typeId)->exists();
                    if (! $closing) {
                        $issues[] = 'belum ada jenis transaksi yang menutup jenis ini (atur "Berpasangan" pada jenis penutupnya).';
                    }
                } else {
                    $issues[] = 'pilih yang ditunggu: tanggal atau transaksi pasangan.';
                }
                break;
            case 'end':
                if (! in_array($config['result'] ?? 'completed', ['completed', 'rejected', 'cancelled'], true)) {
                    $issues[] = 'hasil akhir tidak dikenal.';
                }
                break;
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  array<string, mixed>  $assignee
     * @return list<string>
     */
    private static function assigneeIssues(array $assignee, \Closure $field, bool $approval): array
    {
        $type = $assignee['type'] ?? null;
        $id = $assignee['id'] ?? null;
        $who = $approval ? 'penyetuju' : 'penanggung jawab';

        if ($type === 'role') {
            $role = is_string($id) ? Role::query()->find($id) : null;
            if ($role === null) {
                return ["{$who} (role) tidak ditemukan."];
            }

            return $approval && ! RoleTemplates::mayHoldApproval($role->template_code)
                ? ['penyetuju harus role Super Admin atau Supervisor.'] : [];
        }
        if ($type === 'user') {
            $member = is_string($id) && OrganizationMember::query()->where('user_id', $id)->where('status', User::STATUS_ACTIVE)->exists();
            if (! $member) {
                return ["{$who} (pengguna) tidak ditemukan atau tidak aktif."];
            }
            $approver = Role::query()->whereIn('id', fn ($q) => $q->select('role_id')->from('user_roles')->where('user_id', $id))
                ->get()->contains(fn (Role $r) => RoleTemplates::mayHoldApproval($r->template_code));

            return $approval && ! $approver ? ['penyetuju harus memegang role Super Admin atau Supervisor.'] : [];
        }
        if ($approval && $type === 'location_supervisor') {
            return [];
        }
        if (! $approval && $type === 'starter') {
            return [];
        }
        if (! $approval && $type === 'field') {
            return $field($assignee['field'] ?? null, 'user') ? [] : ['field pengguna penanggung jawab tidak ada di jenis ini.'];
        }

        return ["pilih {$who}."];
    }

    /**
     * @param  list<mixed>  $rules
     * @param  Collection<string, TransactionTypeField>  $byKey
     * @return list<string>
     */
    private static function ruleIssues(array $rules, Collection $byKey): array
    {
        $issues = [];
        foreach ($rules as $rule) {
            $subject = is_array($rule) ? ($rule['subject'] ?? null) : null;
            $known = in_array($subject, self::SUBJECTS, true)
                || (is_string($subject) && str_starts_with($subject, 'field.') && isset($byKey[substr($subject, 6)]));
            if (! $known || ! in_array($rule['op'] ?? null, self::OPERATORS, true)) {
                $issues[] = 'syarat memakai data atau operator yang tidak dikenal.';
            }
        }

        return $issues;
    }

    private static function branchMessage(string $type, string $label): string
    {
        return match ($type) {
            'approval' => "Persetujuan {$label} harus memiliki tepat satu garis Disetujui dan satu garis Ditolak.",
            'condition' => "Kondisi {$label} harus memiliki tepat satu garis Ya dan satu garis Tidak.",
            'end' => "Langkah Selesai {$label} tidak boleh memiliki garis keluar.",
            default => "Langkah {$label} harus memiliki tepat satu garis keluar.",
        };
    }

    /**
     * Nodes reachable from $from following edges $from → $to.
     *
     * @param  Collection<int, array<string, mixed>>  $edges
     * @return list<string>
     */
    private static function reach(string $from, Collection $edges, string $fromKey, string $toKey): array
    {
        $seen = [$from => true];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($edges->where($fromKey, $current) as $edge) {
                if (! isset($seen[$edge[$toKey]])) {
                    $seen[$edge[$toKey]] = true;
                    $queue[] = $edge[$toKey];
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * @param  list<string>  $nodeIds
     * @param  Collection<int, array<string, mixed>>  $edges
     */
    private static function hasCycle(array $nodeIds, Collection $edges): bool
    {
        $inSet = array_flip($nodeIds);
        $state = [];
        $visit = function (string $id) use (&$visit, &$state, $inSet, $edges): bool {
            $state[$id] = 1;
            foreach ($edges->where('source', $id) as $edge) {
                $next = $edge['target'];
                if (! isset($inSet[$next])) {
                    continue;
                }
                if (($state[$next] ?? 0) === 1 || (($state[$next] ?? 0) === 0 && $visit($next))) {
                    return true;
                }
            }
            $state[$id] = 2;

            return false;
        };
        foreach ($nodeIds as $id) {
            if (($state[$id] ?? 0) === 0 && $visit($id)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph */
    public static function node(array $graph, string $id): ?array
    {
        foreach ($graph['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        return null;
    }

    /** @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph */
    public static function next(array $graph, string $from, ?string $outcome = null): ?string
    {
        foreach ($graph['edges'] as $edge) {
            if ($edge['source'] === $from && ($edge['outcome'] ?? null) === $outcome) {
                return $edge['target'];
            }
        }

        return null;
    }
}
