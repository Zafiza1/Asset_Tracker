<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Support\Collection;

/**
 * Turns a flow graph into plain Indonesian sentences (F-08 §3.3, §3.6; D-41, D-43): one source
 * for the editor, the "Cara kerja" documentation, the run page and emails. Deterministic: the
 * same graph and data always give the same text, and no AI is involved. Values are shown by
 * their labels (names, status labels, Rp amounts), never codes or ids; a reference that no
 * longer exists reads "(tidak ditemukan)".
 *
 * Text is plain: the client renders it as text, never as HTML (F-08 §6).
 */
final class FlowDescriber
{
    private const MISSING = '(tidak ditemukan)';

    /** Order in which branches are walked when numbering steps: the main path first. */
    private const OUTCOME_ORDER = [null => 0, 'approved' => 1, 'yes' => 2, 'no' => 3, 'rejected' => 4];

    /** @var array<string, array<string, string>> id → name per kind, filled on first use */
    private array $names = [];

    private TransactionTypeDefinition $type;

    /** @var Collection<string, TransactionTypeField> */
    private Collection $fields;

    /**
     * "Cara kerja transaksi ini" (F08-23): who records, preconditions, the form, numbered steps,
     * the effects on the asset, who gets tasks and emails, a short summary for the form (F08-24)
     * and, given the previous version's graph, what changed (F08-25).
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null  $graph  null: the type records at once
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null  $previous
     * @param  list<array<string, mixed>>  $rules  the version's named rules (F-08g)
     * @param  list<array<string, mixed>>|null  $previousRules  the previous version's, to name what changed
     * @return array<string, mixed>
     */
    public function describe(TransactionTypeDefinition $type, ?array $graph, ?array $previous = null, array $rules = [], ?array $previousRules = null): array
    {
        $this->use($type);
        $steps = $graph === null ? [] : $this->steps($graph);
        // In step order, so the documentation reads from the start to the end.
        $nodes = $graph === null ? collect() : collect($steps)->mapWithKeys(fn ($s) => [$s['id'] => collect($graph['nodes'])->firstWhere('id', $s['id'])]);
        $start = $nodes->firstWhere('type', 'start');
        $later = $nodes->where('type', 'input')->flatMap(fn ($n) => array_map(fn ($k) => [$k, $n['label'] ?? $n['id']], (array) ($n['config']['fields'] ?? [])))
            ->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1]]);

        $effects = $nodes->where('type', 'action')
            ->flatMap(fn ($n) => array_map(fn ($a) => $this->action(is_array($a) ? $a : []), (array) ($n['config']['actions'] ?? [])))
            ->filter(fn ($s) => ! str_starts_with($s, 'Email'))->unique()->values()->all();
        $people = $nodes->whereIn('type', ['input', 'approval'])->map(fn ($n) => $n['type'] === 'approval'
            ? $this->approverPhrase($n['config'] ?? [], true).' menyetujui di langkah "'.($n['label'] ?? $n['id']).'"'
            : $this->assigneePhrase($n['config']['assignee'] ?? ['type' => 'starter']).' mengisi data di langkah "'.($n['label'] ?? $n['id']).'"')
            ->merge($nodes->where('type', 'action')->flatMap(fn ($n) => collect((array) ($n['config']['actions'] ?? []))
                ->filter(fn ($a) => is_array($a) && ($a['kind'] ?? null) === 'notify')
                ->map(fn ($a) => 'Email dikirim ke '.$this->lcfirst($this->assigneePhrase(is_array($a['to'] ?? null) ? $a['to'] : ['type' => 'starter']))))
            )->map(fn ($s) => $this->ucfirst($s))->unique()->values()->all();

        return [
            'title' => $type->name,
            'purpose' => $type->description,
            'records_at_once' => $graph === null,
            'who_records' => $this->whoRecords($start['config'] ?? []),
            'preconditions' => $start === null ? null : ($this->join([$this->preconditions($start['config'] ?? []), ...$this->rejections($start['config'] ?? [])]) ?: null),
            'form' => $this->fields->values()->map(fn (TransactionTypeField $f) => [
                'key' => $f->key, 'label' => $f->label, 'type' => TransactionFieldTypes::TYPES[$f->data_type] ?? $f->data_type,
                'required' => (bool) $f->is_required, 'help' => $f->settings['help'] ?? null,
                'asked_in' => $later[$f->key] ?? null,
            ])->all(),
            'steps' => $steps,
            'effects' => $effects,
            'people' => $people,
            'summary' => $this->summary($graph, $nodes, $effects),
            'rules' => $this->rules($rules, $graph),
            'changes' => $previous === null || $graph === null ? [] : [
                ...$this->changes(RuleCompiler::strip($previous), RuleCompiler::strip($graph)),
                ...$this->ruleChanges($previousRules ?? [], $rules, $graph),
            ],
        ];
    }

    /**
     * The named rules as a list for the Rules tab (F08-35): the sentence of each, and why an
     * active rule does not apply (overruled, F08-38).
     *
     * @param  list<array<string, mixed>>  $rules
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null  $graph
     * @return list<array<string, mixed>>
     */
    private function rules(array $rules, ?array $graph): array
    {
        $overruled = RuleCompiler::overruled($rules);
        $names = collect($rules)->pluck('name', 'id');

        return array_map(fn (array $rule) => [
            'id' => $rule['id'],
            'name' => $rule['name'],
            'description' => $rule['description'] ?? null,
            'priority' => (int) $rule['priority'],
            'active' => (bool) $rule['active'],
            'kind' => $rule['outcome']['kind'],
            'sentence' => $this->ruleSentence($rule, $graph),
            'applies' => (bool) $rule['active'] && ! isset($overruled[$rule['id']]),
            'note' => isset($overruled[$rule['id']])
                ? 'Bertentangan dengan rule "'.$names[$overruled[$rule['id']]].'" yang prioritasnya lebih tinggi; rule ini tidak berlaku.'
                : (! $rule['active'] ? 'Nonaktif; rule ini tidak berlaku.' : null),
        ], RuleCompiler::ordered($rules));
    }

    /**
     * "Bila nilai perolehan lebih dari Rp 10.000.000, perlu disetujui pemegang role Super Admin."
     *
     * @param  array<string, mixed>  $rule
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null  $graph
     */
    private function ruleSentence(array $rule, ?array $graph): string
    {
        $conditions = array_values(array_filter((array) ($rule['conditions'] ?? []), 'is_array'));
        $when = $conditions === [] ? 'Selalu' : 'Bila '.$this->rulesPhrase($conditions, $rule['match'] ?? 'all', $rule['assets'] ?? 'any').',';
        $outcome = $rule['outcome'] ?? [];
        $step = $graph === null ? null : collect($graph['nodes'])->firstWhere('id', $outcome['step'] ?? null);

        return $when.' '.match ($outcome['kind'] ?? null) {
            'reject' => 'transaksi tidak dapat dicatat. Pesan: "'.trim((string) ($outcome['message'] ?? '')).'"',
            'approval' => $this->lcfirst($this->approverPhrase($outcome, false)).'.',
            'skip_approval' => 'langkah "'.($step === null ? ($outcome['step'] ?? '?').'" '.self::MISSING : $this->label($step).'"').' dilewati.',
            default => 'hasil tidak dikenal.',
        };
    }

    /**
     * What changed in the named rules (F08-37): added, removed, switched on or off, changed.
     *
     * @param  list<array<string, mixed>>  $old
     * @param  list<array<string, mixed>>  $new
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<string>
     */
    private function ruleChanges(array $old, array $new, array $graph): array
    {
        $old = collect($old)->keyBy('id');
        $new = collect($new)->keyBy('id');
        $changes = [];
        foreach ($new as $id => $rule) {
            $name = '"'.$rule['name'].'"';
            $before = $old[$id] ?? null;
            if ($before === null) {
                $changes[] = "Rule {$name} ditambahkan".($rule['active'] ? '' : ' (nonaktif)').': '.$this->ruleSentence($rule, $graph);

                continue;
            }
            if ($before['name'] !== $rule['name']) {
                $changes[] = 'Rule "'.$before['name'].'" diganti nama menjadi '.$name.'.';
            }
            if ($before['active'] !== $rule['active']) {
                $changes[] = "Rule {$name} ".($rule['active'] ? 'diaktifkan.' : 'dinonaktifkan.');
            }
            $sentence = $this->ruleSentence($rule, $graph);
            $was = $this->ruleSentence($before, $graph);
            if ($sentence !== $was) {
                $changes[] = "Rule {$name} diubah: {$sentence} (sebelumnya: {$was})";
            }
            if ((int) $before['priority'] !== (int) $rule['priority']) {
                $changes[] = "Prioritas rule {$name} diubah dari {$before['priority']} menjadi {$rule['priority']}.";
            }
        }
        foreach ($old as $id => $rule) {
            if (! isset($new[$id])) {
                $changes[] = 'Rule "'.$rule['name'].'" dihapus.';
            }
        }

        return $changes;
    }

    /**
     * The sentence of one step, as used on the run page; $graph gives the branch targets.
     *
     * @param  array<string, mixed>  $node
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    public function stepSentence(TransactionTypeDefinition $type, array $node, array $graph): string
    {
        $this->use($type);

        return $this->sentence($node, collect($graph['nodes'])->keyBy('id'), collect($graph['edges']), null);
    }

    /**
     * One condition rule as a phrase, e.g. "nilai perolehan lebih dari Rp 10.000.000" (F08-10).
     *
     * @param  array<string, mixed>  $rule
     */
    public function rule(TransactionTypeDefinition $type, array $rule, string $assets = 'any'): string
    {
        $this->use($type);

        return $this->rulePhrase($rule, $assets);
    }

    /** A type not saved yet (a template preview, F-08c) brings its fields as the loaded relation. */
    private function use(TransactionTypeDefinition $type): void
    {
        $this->type = $type;
        $this->fields = ($type->exists ? $type->fields()->get() : $type->fields)->keyBy('key');
    }

    /**
     * Numbered steps in walking order: the main path first, then the branches; steps no line
     * reaches come last so nothing drawn is left out.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<array{number: int, id: string, type: string, label: string, sentence: string}>
     */
    private function steps(array $graph): array
    {
        $nodes = collect($graph['nodes'])->keyBy('id');
        $edges = collect($graph['edges']);
        $order = $this->order($nodes, $edges);
        $numbers = array_flip($order);

        $out = [];
        foreach ($order as $i => $id) {
            $node = $nodes[$id];
            $out[] = [
                'number' => $i + 1, 'id' => $id, 'type' => $node['type'], 'label' => $this->label($node),
                'sentence' => $this->sentence($node, $nodes, $edges, $numbers),
            ];
        }

        return $out;
    }

    /** @return list<string> node ids, depth first along the main path, ends after the steps that lead to them */
    private function order(Collection $nodes, Collection $edges): array
    {
        $seen = [];
        $ends = [];
        $walk = function (string $id) use (&$walk, &$seen, &$ends, $nodes, $edges) {
            if (isset($seen[$id]) || in_array($id, $ends, true) || ! isset($nodes[$id])) {
                return;
            }
            if ($nodes[$id]['type'] === 'end') {
                $ends[] = $id;

                return;
            }
            $seen[$id] = true;
            $edges->where('source', $id)
                ->sortBy(fn ($e) => self::OUTCOME_ORDER[$e['outcome'] ?? null] ?? 9)
                ->each(fn ($e) => $walk((string) $e['target']));
        };
        $start = $nodes->firstWhere('type', 'start');
        if ($start !== null) {
            $walk((string) $start['id']);
        }
        $order = [...array_keys($seen), ...$ends];
        foreach ($nodes->keys() as $id) {
            if (! in_array($id, $order, true)) {
                $order[] = (string) $id;
            }
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, int>|null  $numbers  step numbers by id; null when not numbered
     */
    private function sentence(array $node, Collection $nodes, Collection $edges, ?array $numbers): string
    {
        $config = $node['config'] ?? [];
        $next = fn (?string $outcome) => $edges->first(fn ($e) => $e['source'] === $node['id'] && ($e['outcome'] ?? null) === $outcome)['target'] ?? null;
        $goTo = function (?string $target) use ($nodes) {
            $to = $target === null ? null : ($nodes[$target] ?? null);
            if ($to === null) {
                return 'berhenti (belum ada langkah berikutnya)';
            }

            return $to['type'] === 'end' ? $this->lcfirst($this->endSentence($to['config'] ?? [])) : 'lanjut ke "'.$this->label($to).'"';
        };
        // Whether $target is simply the step after this one, so it need not be named.
        $isNext = fn (?string $target) => $numbers !== null && $target !== null && isset($numbers[$target], $numbers[$node['id']])
            && $numbers[$target] === $numbers[$node['id']] + 1 && ($nodes[$target]['type'] ?? null) !== 'end';

        return match ($node['type']) {
            'start' => $this->join([$this->whoRecords($config), $this->preconditions($config), ...$this->rejections($config)]),
            'input' => $this->inputSentence($config),
            'approval' => $this->join([
                $this->approverPhrase($config, false).'.',
                ($config['allow_self'] ?? false) ? 'Pencatat boleh menyetujui transaksinya sendiri.' : null,
                $isNext($next('approved')) ? null : 'Bila disetujui, '.$goTo($next('approved')).'.',
                'Bila ditolak, '.$goTo($next('rejected')).'.',
            ]),
            'condition' => 'Bila '.$this->rulesPhrase((array) ($config['rules'] ?? []), $config['match'] ?? 'all', $config['assets'] ?? 'any').', '
                .$goTo($next('yes')).'; bila tidak, '.$goTo($next('no')).'.',
            'action' => $this->join(array_map(fn ($a) => $this->period($this->action(is_array($a) ? $a : [])), (array) ($config['actions'] ?? []))),
            'wait' => $this->waitSentence($config),
            'end' => $this->endSentence($config).'.',
            default => '',
        };
    }

    /** @param array<string, mixed> $config of the start step */
    private function whoRecords(array $config): string
    {
        $roles = array_map(fn ($id) => $this->name('role', $id), (array) ($config['roles'] ?? []));

        return $roles === []
            ? 'Dapat dicatat oleh semua pengguna yang berizin mencatat transaksi'.($this->type->coreType() !== null ? ' '.mb_strtolower(TransactionType::LABELS[$this->type->coreType()] ?? '') : '').'.'
            : 'Dapat dicatat oleh '.$this->list($roles, 'dan').'.';
    }

    /**
     * Preconditions read as a ban when there is one rule (F08-11), else as a requirement.
     *
     * @param  array<string, mixed>  $config  of the start step
     */
    private function preconditions(array $config): ?string
    {
        $pre = $config['preconditions'] ?? [];
        $rules = array_values(array_filter((array) ($pre['rules'] ?? []), 'is_array'));
        if ($rules === []) {
            return null;
        }
        $assets = $pre['assets'] ?? 'all';
        $sentence = count($rules) === 1 && ($negated = $this->negate($rules[0])) !== null
            // "Every asset must pass" is broken by any asset failing, and the other way round.
            ? 'Transaksi tidak dapat dicatat bila '.$this->rulePhrase($negated, ['all' => 'any', 'any' => 'all'][$assets] ?? $assets).'.'
            : 'Transaksi hanya dapat dicatat bila '.$this->rulesPhrase($rules, $pre['match'] ?? 'all', $assets).'.';
        $message = trim((string) ($config['message'] ?? ''));

        return $message === '' ? $sentence : "{$sentence} Pesan: \"{$message}\"";
    }

    /** @param array<string, mixed> $config of an input step */
    private function inputSentence(array $config): string
    {
        $required = (array) ($config['required'] ?? []);
        $fields = array_map(fn ($key) => $this->fieldLabel((string) $key).(in_array($key, $required, true) ? ' (wajib)' : ''), (array) ($config['fields'] ?? []));
        $days = $config['due_days'] ?? null;

        return $this->assigneePhrase($config['assignee'] ?? ['type' => 'starter']).' mengisi '.($fields === [] ? 'data' : $this->list($fields, 'dan'))
            .(is_int($days) ? " dalam {$days} hari" : '').'.';
    }

    /**
     * Refusals compiled from named rules (F-08g), each read as a ban with its message (F08-11).
     *
     * @param  array<string, mixed>  $config  of the start step
     * @return list<string>
     */
    private function rejections(array $config): array
    {
        return array_values(array_map(fn (array $r) => 'Transaksi tidak dapat dicatat bila '
            .$this->rulesPhrase((array) ($r['rules'] ?? []), $r['match'] ?? 'all', $r['assets'] ?? 'any')
            .'. Pesan: "'.trim((string) ($r['message'] ?? '')).'"', array_filter((array) ($config['rejections'] ?? []), 'is_array')));
    }

    /** @param array<string, mixed> $config of a wait step */
    private function waitSentence(array $config): string
    {
        if (($config['until'] ?? null) === 'paired') {
            $closing = $this->type->exists ? TransactionTypeDefinition::query()->where('closes_type_id', $this->type->id)->orderBy('name')->pluck('name')->all() : [];

            return 'Menunggu transaksi '.($closing === [] ? 'pasangannya' : $this->list(array_map(fn ($n) => "\"{$n}\"", $closing), 'atau')).' untuk setiap aset.';
        }

        return 'Menunggu sampai tanggal pada field '.$this->fieldLabel((string) ($config['field'] ?? '')).'.';
    }

    /** @param array<string, mixed> $config of an end step */
    private function endSentence(array $config): string
    {
        return match ($config['result'] ?? 'completed') {
            'rejected' => 'Transaksi selesai sebagai Ditolak',
            'cancelled' => 'Transaksi dibatalkan',
            default => 'Transaksi selesai',
        };
    }

    /** @param array<string, mixed> $action */
    private function action(array $action): string
    {
        $field = fn (?string $key) => $this->fieldLabel((string) $key);

        return match ($action['kind'] ?? null) {
            FlowActions::CORE => ($core = $this->type->coreType()) !== null
                ? (TransactionType::LABELS[$core] ?? $core).' dicatat sesuai isian formulir, dengan aturan bawaannya'
                : 'Aksi inti dicatat',
            'set_status' => 'Status aset diubah menjadi '.(AssetStatus::LABELS[$action['status'] ?? ''] ?? self::MISSING),
            'move_to_field_location' => 'Aset dipindah ke lokasi pada field '.$field($action['field'] ?? null),
            'set_condition' => isset($action['field'])
                ? 'Kondisi aset diubah sesuai field '.$field($action['field'])
                : 'Kondisi aset diubah menjadi '.$this->conditionLabel((string) ($action['value'] ?? '')),
            'loan_to_field_user' => 'Aset dipinjamkan ke pengguna pada field '.$field($action['user_field'] ?? null)
                .(isset($action['due_field']) ? ', rencana kembali dari field '.$field($action['due_field']) : ''),
            'return' => 'Peminjaman aset diakhiri'.(isset($action['location_field']) ? ' dan aset kembali ke lokasi pada field '.$field($action['location_field']) : ''),
            'notify' => 'Email dikirim ke '.$this->lcfirst($this->assigneePhrase(is_array($action['to'] ?? null) ? $action['to'] : ['type' => 'starter']))
                .(trim((string) ($action['message'] ?? '')) !== '' ? ': "'.trim((string) $action['message']).'"' : ''),
            default => 'Aksi tidak dikenal',
        };
    }

    /** @param array<string, mixed> $config of an approval step */
    private function approverPhrase(array $config, bool $short): string
    {
        $approver = $config['approver'] ?? [];
        $min = max(1, (int) ($config['min_approvals'] ?? 1));
        $who = match ($approver['type'] ?? null) {
            'role' => ($min > 1 ? "{$min} pemegang" : 'Pemegang').' role '.$this->name('role', $approver['id'] ?? null),
            'user' => $this->name('user', $approver['id'] ?? null),
            'location_supervisor' => ($min > 1 ? "{$min} " : '').'Supervisor yang membawahi aset',
            'asset_custodian' => 'Penanggung jawab setiap aset (bila belum ada atau tidak aktif: Supervisor yang membawahi aset; pencatat yang menjadi penanggung jawab dianggap menyetujui)',
            'target_department_supervisor' => 'Supervisor departemen asal '.$this->fieldLabel((string) ($approver['field'] ?? '')).' (bila tidak ada: Super Admin)',
            default => 'Penyetuju '.self::MISSING,
        };

        return $short ? $who : 'Perlu disetujui '.$this->lcfirst($who);
    }

    /** @param array<string, mixed> $assignee */
    private function assigneePhrase(array $assignee): string
    {
        return match ($assignee['type'] ?? 'starter') {
            'role' => 'Pemegang role '.$this->name('role', $assignee['id'] ?? null),
            'user' => $this->name('user', $assignee['id'] ?? null),
            'field' => 'Pengguna pada field '.$this->fieldLabel((string) ($assignee['field'] ?? '')),
            default => 'Pencatat',
        };
    }

    /** @param list<array<string, mixed>> $rules */
    private function rulesPhrase(array $rules, string $match, string $assets): string
    {
        $parts = array_map(fn ($r) => $this->rulePhrase($r, $assets), array_values(array_filter($rules, 'is_array')));

        return $parts === [] ? '(tanpa syarat)' : implode($match === 'any' ? ' atau ' : ' dan ', $parts);
    }

    /** @param array<string, mixed> $rule */
    private function rulePhrase(array $rule, string $assets): string
    {
        $subject = (string) ($rule['subject'] ?? '');
        $op = (string) ($rule['op'] ?? '');
        $values = array_map(fn ($v) => $this->valueLabel($subject, $v), array_values(is_array($rule['value'] ?? null) ? $rule['value'] : [$rule['value'] ?? null]));

        $many = $this->type->multi_asset;
        $ofAsset = $many ? ($assets === 'all' ? ' setiap aset' : ' salah satu aset') : ' aset';
        if ($subject === 'asset.custodian') {
            return ($op === 'empty' ? 'belum ada penanggung jawab' : 'ada penanggung jawab').$ofAsset;
        }
        if ($subject === 'starter.is_custodian') {
            $is = ($rule['value'] ?? true) === true;

            return 'pencatat '.(($op === 'eq') === $is ? 'adalah' : 'bukan').' penanggung jawab'.$ofAsset;
        }
        if ($subject === 'target.department_differs') {
            return 'departemen asal '.$this->fieldLabel((string) ($rule['value'] ?? '')).' '.($op === 'eq' ? 'berbeda dengan' : 'sama dengan').' departemen'.$ofAsset;
        }
        if ($subject === 'core.outgoing_nature') {
            return 'sifat transaksi keluar '.match ($op) {
                'neq' => 'bukan '.$values[0], 'not_in' => 'bukan '.$this->list($values, 'maupun'), 'in' => 'salah satu dari '.$this->list($values, 'atau'), default => 'adalah '.$values[0],
            };
        }

        if ($subject === 'starter.role') {
            return match ($op) {
                'empty' => 'pencatat tidak memiliki role',
                'not_empty' => 'pencatat memiliki role',
                'neq', 'not_in' => 'pencatat tidak memiliki role '.$this->list($values, 'atau'),
                default => 'pencatat memiliki role '.$this->list($values, 'atau'),
            };
        }

        $name = match (true) {
            $subject === 'asset.acquisition_cost' => ! $many ? 'nilai perolehan' : match ($assets) {
                'total' => 'total nilai perolehan', 'all' => 'nilai perolehan setiap aset', default => 'nilai perolehan salah satu aset',
            },
            str_starts_with($subject, 'asset.') => [
                'asset.status' => 'status', 'asset.condition' => 'kondisi', 'asset.category' => 'kategori', 'asset.location' => 'lokasi', 'asset.department' => 'departemen',
            ][$subject] ?? $subject,
            str_starts_with($subject, 'field.') => $this->fieldLabel(substr($subject, 6)),
            default => $subject,
        };
        if (str_starts_with($subject, 'asset.') && $subject !== 'asset.acquisition_cost') {
            $name .= $many ? ($assets === 'all' ? ' setiap aset' : ' salah satu aset') : ' aset';
        }

        $category = $subject === 'asset.category';
        $location = $subject === 'asset.location';
        $phrase = match ($op) {
            'eq' => ($category ? 'termasuk ' : ($location ? 'berada di ' : 'adalah ')).$values[0],
            'neq' => ($category ? 'tidak termasuk ' : ($location ? 'tidak berada di ' : 'bukan ')).$values[0],
            'in' => ($category ? 'termasuk salah satu dari ' : ($location ? 'berada di salah satu dari ' : 'salah satu dari ')).$this->list($values, 'atau'),
            'not_in' => ($category ? 'tidak termasuk ' : ($location ? 'tidak berada di ' : 'bukan ')).$this->list($values, 'maupun'),
            'gt' => 'lebih dari '.$values[0],
            'gte' => 'paling sedikit '.$values[0],
            'lt' => 'kurang dari '.$values[0],
            'lte' => 'paling banyak '.$values[0],
            'empty' => 'kosong',
            'not_empty' => 'terisi',
            default => $op,
        };

        return "{$name} {$phrase}".($category && in_array($op, ['eq', 'in'], true) ? ' (beserta subkategorinya)' : '');
    }

    /**
     * The opposite rule, to write a single precondition as a ban; null when there is none.
     *
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>|null
     */
    private function negate(array $rule): ?array
    {
        $opposite = ['eq' => 'neq', 'neq' => 'eq', 'in' => 'not_in', 'not_in' => 'in', 'gt' => 'lte', 'lte' => 'gt',
            'gte' => 'lt', 'lt' => 'gte', 'empty' => 'not_empty', 'not_empty' => 'empty'][$rule['op'] ?? ''] ?? null;

        return $opposite === null ? null : [...$rule, 'op' => $opposite];
    }

    private function valueLabel(string $subject, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match (true) {
            $subject === 'asset.status' => AssetStatus::LABELS[(string) $value] ?? self::MISSING,
            $subject === 'asset.condition' => $this->conditionLabel((string) $value),
            $subject === 'asset.category' => $this->name('category', $value),
            $subject === 'asset.location' => $this->name('location', $value),
            $subject === 'asset.department' => $this->name('department', $value),
            $subject === 'core.outgoing_nature' => OutgoingReason::NATURE_LABELS[(string) $value] ?? self::MISSING,
            $subject === 'asset.acquisition_cost' => is_numeric($value) ? 'Rp '.number_format((float) $value, 0, ',', '.') : (string) $value,
            $subject === 'starter.role' => $this->name('role', $value),
            str_starts_with($subject, 'field.') => $this->fieldValue(substr($subject, 6), $value),
            default => is_scalar($value) ? (string) $value : self::MISSING,
        };
    }

    private function fieldValue(string $key, mixed $value): string
    {
        $field = $this->fields[$key] ?? null;
        if ($field === null) {
            return is_scalar($value) ? (string) $value : self::MISSING;
        }
        if ($field->data_type === 'boolean') {
            return in_array($value, [true, 1, '1', 'true'], true) ? 'Ya' : 'Tidak';
        }

        return TransactionFieldTypes::display([$field], [$key => $value])[$key] ?? self::MISSING;
    }

    private function fieldLabel(string $key): string
    {
        return isset($this->fields[$key]) ? (string) $this->fields[$key]->label : "\"{$key}\" ".self::MISSING;
    }

    private function conditionLabel(string $value): string
    {
        $options = collect(CategoryAttributeService::DEFAULT_ATTRIBUTES['status_condition'])
            ->firstWhere('label', CategoryAttributeService::CONDITION_LABEL)['options'] ?? [];
        $option = collect($options)->first(fn ($o) => strcasecmp($o['value'], $value) === 0 || strcasecmp($o['label'], $value) === 0);

        return $option['label'] ?? ($value === '' ? self::MISSING : $value);
    }

    private function name(string $kind, mixed $id): string
    {
        if (! is_string($id) || $id === '') {
            return self::MISSING;
        }
        if (! array_key_exists($id, $this->names[$kind] ?? [])) {
            $this->names[$kind][$id] = match ($kind) {
                'role' => Role::query()->whereKey($id)->value('name'),
                'user' => User::query()->whereKey($id)->value('name'),
                'location' => Location::query()->whereKey($id)->value('name'),
                'category' => TenantAssetCategory::query()->whereKey($id)->value('name'),
                'department' => Department::query()->whereKey($id)->value('name'),
            } ?? self::MISSING;
        }

        return $this->names[$kind][$id];
    }

    /**
     * Two to four sentences for the transaction form (F08-24).
     *
     * @param  list<string>  $effects
     */
    private function summary(?array $graph, Collection $nodes, array $effects): string
    {
        if ($graph === null) {
            return 'Transaksi ini langsung tercatat tanpa persetujuan.';
        }
        $edges = collect($graph['edges']);
        $who = fn (Collection $approvals) => $approvals->map(fn ($n) => $this->lcfirst($this->approverPhrase($n['config'] ?? [], true)))->unique()->values()->all();
        [$always, $sometimes] = $nodes->where('type', 'approval')->partition(fn ($n) => $this->onEveryCompletedPath($n['id'], $nodes, $edges));
        $always = $who($always);
        $sometimes = array_values(array_diff($who($sometimes), $always));
        $inputs = $nodes->where('type', 'input')->count();
        $sentences = [
            match (true) {
                $always === [] && $sometimes === [] => 'Transaksi ini tidak memerlukan persetujuan.',
                $always === [] => 'Pada kondisi tertentu, transaksi ini perlu disetujui '.$this->list($sometimes, 'atau').'.',
                default => 'Transaksi ini perlu disetujui '.implode(', lalu ', $always).'.'
                    .($sometimes === [] ? '' : ' Pada kondisi tertentu, juga perlu disetujui '.$this->list($sometimes, 'atau').'.'),
            },
            $inputs > 0 ? 'Ada '.$inputs.' langkah isi data setelah transaksi diajukan.' : null,
            $effects === [] ? 'Transaksi ini tidak mengubah data aset.' : 'Hasilnya: '.$this->lcfirst($this->list(array_map(fn ($e) => $this->lcfirst($e), $effects), 'dan')).'.',
        ];

        return $this->join($sentences);
    }

    /** Whether every way from the start to a completed end passes $id (the step is always met). */
    private function onEveryCompletedPath(string $id, Collection $nodes, Collection $edges): bool
    {
        $start = $nodes->firstWhere('type', 'start');
        if ($start === null) {
            return false;
        }
        $seen = [$id => true];
        $queue = [(string) $start['id']];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            $node = $nodes[$current] ?? null;
            if ($node !== null && $node['type'] === 'end' && ($node['config']['result'] ?? 'completed') === 'completed') {
                return false;
            }
            foreach ($edges->where('source', $current) as $edge) {
                $queue[] = (string) $edge['target'];
            }
        }

        return true;
    }

    /**
     * What changed from $old to $new, as sentences (F08-25).
     *
     * @return list<string>
     */
    private function changes(array $old, array $new): array
    {
        $oldNodes = collect($old['nodes'])->keyBy('id');
        $newNodes = collect($new['nodes'])->keyBy('id');
        $oldEdges = collect($old['edges']);
        $newEdges = collect($new['edges']);
        $changes = [];

        foreach ($newNodes as $id => $node) {
            $sentence = $this->sentence($node, $newNodes, $newEdges, null);
            if (! isset($oldNodes[$id])) {
                $changes[] = 'Langkah "'.$this->label($node).'" ditambahkan: '.$sentence;

                continue;
            }
            $before = $this->sentence($oldNodes[$id], $oldNodes, $oldEdges, null);
            $renamed = $this->label($oldNodes[$id]) !== $this->label($node);
            if ($renamed) {
                $changes[] = 'Langkah "'.$this->label($oldNodes[$id]).'" diganti nama menjadi "'.$this->label($node).'".';
            }
            // Branch targets are named in the sentence, so a renamed target alone is not a change of this step.
            if ($before !== $sentence && ! $this->onlyTargetsRenamed($oldNodes[$id], $node, $oldNodes, $newNodes, $oldEdges, $newEdges)) {
                $changes[] = 'Langkah "'.$this->label($node).'" diubah: '.$sentence.' (sebelumnya: '.$before.')';
            }
        }
        foreach ($oldNodes as $id => $node) {
            if (! isset($newNodes[$id])) {
                $changes[] = 'Langkah "'.$this->label($node).'" dihapus.';
            }
        }
        if ($changes === [] && $this->order($oldNodes, $oldEdges) !== $this->order($newNodes, $newEdges)) {
            $changes[] = 'Urutan langkah diubah.';
        }

        return $changes;
    }

    private function onlyTargetsRenamed(array $oldNode, array $newNode, Collection $oldNodes, Collection $newNodes, Collection $oldEdges, Collection $newEdges): bool
    {
        if (($oldNode['config'] ?? []) != ($newNode['config'] ?? []) || $oldNode['type'] !== $newNode['type']) {
            return false;
        }
        $targets = fn (Collection $edges, string $id) => $edges->where('source', $id)->mapWithKeys(fn ($e) => [(string) ($e['outcome'] ?? '') => $e['target']])->sortKeys()->all();

        return $targets($oldEdges, $oldNode['id']) === $targets($newEdges, $newNode['id']);
    }

    /** @param array<string, mixed> $node */
    private function label(array $node): string
    {
        $label = trim((string) ($node['label'] ?? ''));

        return $label !== '' ? $label : (string) $node['id'];
    }

    /** @param list<string> $items */
    private function list(array $items, string $last): string
    {
        $items = array_values($items);

        return count($items) <= 1 ? ($items[0] ?? '') : implode(', ', array_slice($items, 0, -1))." {$last} ".end($items);
    }

    /** Ends a sentence with a full stop, unless a quoted message already ends it. */
    private function period(string $s): string
    {
        return preg_match('/[.!?]"?$/u', $s) === 1 ? $s : "{$s}.";
    }

    /** @param list<string|null> $sentences */
    private function join(array $sentences): string
    {
        return implode(' ', array_filter($sentences, fn ($s) => $s !== null && $s !== ''));
    }

    private function ucfirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    /** Lower-cases the first letter unless the phrase starts with a name (two capitals, e.g. "Supervisor ..." stays). */
    private function lcfirst(string $s): string
    {
        foreach (['Pencatat', 'Pemegang', 'Pengguna', 'Status', 'Aset', 'Kondisi', 'Peminjaman', 'Email', 'Transaksi', 'Penyetuju', 'Perlu'] as $word) {
            if (str_starts_with($s, $word.' ') || $s === $word) {
                return mb_strtolower(mb_substr($s, 0, 1)).mb_substr($s, 1);
            }
        }

        return $s;
    }
}
