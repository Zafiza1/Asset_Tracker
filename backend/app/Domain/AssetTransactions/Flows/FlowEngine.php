<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\TransactionFlowVersion;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionStep;
use App\Domain\AssetTransactions\Models\TransactionTask;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\AssetTransactions\TransactionLog;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\Audit\AuditLogger;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Runs transactions of an organization's type through the type's published flow (F-07 §5).
 *
 * Starting checks who may start, the entered values, the categories, the core rules and the
 * start step's preconditions, locks the assets (D-29) and moves on. Automatic steps (condition,
 * action, wait, end) follow one another at once; a step done by people (input, approval) opens a
 * task and waits for it. A run keeps the flow version it started with (D-28).
 *
 * A wait for the paired transaction ends when every asset of the run got a transaction of the
 * type that closes the run's type; that transaction may take the run's locked assets.
 */
final class FlowEngine
{
    /** Guard against a flow that would keep running automatic steps (FlowGraph refuses such loops). */
    private const MAX_AUTOMATIC_STEPS = 100;

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetScope $scope,
        private readonly TransactionLog $log,
        private readonly TransactionFieldTypes $fieldTypes,
        private readonly FlowActions $actions,
        private readonly RunAccess $access,
        private readonly AuditLogger $audit,
        private readonly AssetTransactionService $transactions,
    ) {}

    /** The type's published flow, if it has one; without, its transactions are recorded at once (F-07a). */
    public function activeFlow(TransactionTypeDefinition $definition): ?TransactionFlowVersion
    {
        return $definition->active_flow_version_id ? TransactionFlowVersion::query()->find($definition->active_flow_version_id) : null;
    }

    /**
     * @param  array<string, mixed>  $data  validated: asset_ids, field_values, notes, transaction_date
     */
    public function start(TransactionTypeDefinition $definition, TransactionFlowVersion $version, array $data): TransactionRun
    {
        if (! $definition->isActive()) {
            throw ApiException::unprocessable('TYPE_INACTIVE', "Jenis \"{$definition->name}\" tidak aktif sehingga tidak dapat dipakai mencatat transaksi.");
        }
        if (! $definition->multi_asset && count($data['asset_ids']) > 1) {
            throw ValidationException::withMessages(['asset_ids' => "Jenis \"{$definition->name}\" hanya untuk satu aset per transaksi."]);
        }
        $definition->loadMissing(['fields', 'categories:id']);
        $graph = $version->graph;
        $startNode = collect($graph['nodes'])->firstWhere('type', 'start') ?? throw new LogicException('Published flow without a start step.');
        $config = $startNode['config'] ?? [];
        $roles = $this->userRoles($this->userId());

        $allowed = (array) ($config['roles'] ?? []);
        if ($allowed !== [] && array_intersect($allowed, $roles) === []) {
            throw ApiException::forbidden('FORBIDDEN', "Role Anda tidak dapat memulai transaksi {$definition->name}.");
        }
        // Fields filled in at a later step are optional now; the rest follow their own is_required.
        $later = collect($graph['nodes'])->where('type', 'input')->flatMap(fn ($n) => (array) ($n['config']['fields'] ?? []))->unique()->all();
        $required = $definition->fields->filter(fn ($f) => $f->is_required && ! in_array($f->key, $later, true))->pluck('key')->all();
        $values = $this->fieldTypes->values($definition->fields, $data['field_values'] ?? [], $this->today(), $required);
        // A system type (F-07f-2), or a copy of one (F-08), keeps the built-in input for its core action.
        $coreType = $definition->coreType();
        $system = $coreType !== null
            ? array_filter(array_intersect_key($data, array_flip(AssetTransactionService::SYSTEM_INPUT)), fn ($v) => $v !== null)
            : [];

        $run = DB::transaction(function () use ($definition, $version, $graph, $startNode, $config, $roles, $values, $data, $system, $coreType) {
            $assets = $this->scope->apply(Asset::query())->with('category:id,path')->whereKey($data['asset_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($assets->count() !== count($data['asset_ids'])) {
                throw new ApiException('NOT_FOUND', 'Aset tidak ditemukan.', 404, ['fields' => ['asset_ids' => ['Aset tidak ditemukan.']]]);
            }
            foreach ($assets as $asset) {
                if ($asset->isArchived()) {
                    throw ApiException::conflict('ASSET_ARCHIVED', "Aset nonaktif {$asset->name} tidak dapat ditransaksikan.");
                }
                AssetTransactionService::assertCategoryAllowed($asset, $definition);
            }
            $closes = AssetTransactionService::assertNotHeldByRun($assets->all(), $definition);
            if ($coreType !== null) {
                // The user may record this built-in type, and its core rules hold now, not only after approval.
                $this->transactions->assertApplicable($coreType, $assets->all(),
                    [...$system, 'transaction_date' => $data['transaction_date'] ?? $this->today(), 'notes' => $data['notes'] ?? null]);
            }

            $pre = $config['preconditions'] ?? [];
            if (! FlowConditions::passes($pre['rules'] ?? [], $pre['match'] ?? 'all', $pre['assets'] ?? 'all', $assets, $values, $roles, $this->userId(), $system)) {
                throw ApiException::unprocessable('PRECONDITION_FAILED', $config['message'] ?? "Syarat untuk memulai transaksi {$definition->name} belum terpenuhi.");
            }
            if (($rejection = self::rejection($config, $assets, $values, $roles, $this->userId(), $system)) !== null) {
                throw ApiException::unprocessable('PRECONDITION_FAILED', $rejection['message'], ['rule_id' => $rejection['rule_id'] ?? null]);
            }

            $run = new TransactionRun;
            $run->forceFill([
                'type_definition_id' => $definition->id,
                'flow_version_id' => $version->id,
                'transaction_number' => $this->log->nextNumber(TransactionType::CUSTOM, $definition->code),
                'state' => TransactionRun::RUNNING,
                'current_node_id' => $startNode['id'],
                'field_values' => $values,
                'system_values' => $system === [] ? null : $system,
                'notes' => $data['notes'] ?? null,
                'transaction_date' => $data['transaction_date'] ?? $this->today(),
                'created_by' => $this->userId(),
            ])->save();
            // Assets still held by the run this one closes stay with that run until it moves on.
            $heldByClosed = $closes === [] ? [] : DB::table('transaction_run_assets')->whereIn('run_id', $closes)->where('locked', true)->pluck('asset_id')->all();
            $orgId = $this->tenancy->organizationId();
            $run->assets()->attach($assets->pluck('id')->mapWithKeys(fn ($id) => [$id => [
                'organization_id' => $orgId, 'locked' => $definition->locks_assets && ! in_array($id, $heldByClosed, true),
            ]])->all());
            $this->fieldTypes->claimAttachments($definition->fields, $values, $run->id);

            $this->step($run, $startNode, 'started', $this->userId(), $data['notes'] ?? null, ['values' => $values]);
            $this->moveTo($run, FlowGraph::next($graph, $startNode['id']));
            $this->advance($run, $graph);
            $this->audit->record('transaction_run.started', $run, after: ['transaction_number' => $run->transaction_number, 'type' => $definition->name, 'assets' => $assets->pluck('name')->all()]);
            $this->resumeRuns($closes);

            return $run;
        });

        return $run->refresh();
    }

    /**
     * Work done on the run's current step: data filled in, or an approval decision. An approval
     * that needs several approvers stays open until enough different people approved; one
     * rejection ends it.
     *
     * @param  array<string, mixed>  $input  decision (approved|rejected), field_values, note
     */
    public function completeTask(TransactionRun $run, TransactionTask $task, array $input): TransactionRun
    {
        return DB::transaction(function () use ($run, $task, $input) {
            $run = TransactionRun::query()->lockForUpdate()->findOrFail($run->id);
            $task = TransactionTask::query()->lockForUpdate()->findOrFail($task->id);
            if (! $run->isRunning() || $task->run_id !== $run->id || $task->status !== TransactionTask::OPEN || $task->node_id !== $run->current_node_id) {
                throw ApiException::conflict('TASK_NOT_OPEN', 'Langkah ini sudah dikerjakan atau transaksi sudah berakhir.');
            }
            $userId = $this->userId();
            if (in_array($userId, $task->approved_by, true)) {
                throw ApiException::conflict('ALREADY_APPROVED', 'Anda sudah menyetujui langkah ini. Persetujuan berikutnya dari penyetuju lain.');
            }
            if (! $this->access->isAssignee($task)) {
                throw ApiException::forbidden('FORBIDDEN', 'Langkah ini bukan tugas Anda.');
            }

            $graph = $run->flowVersion->graph;
            $node = FlowGraph::node($graph, $task->node_id) ?? throw new LogicException('Task on a step that is not in the flow.');
            $config = $node['config'] ?? [];
            $note = isset($input['note']) ? trim((string) $input['note']) : null;

            if ($task->kind === 'approval') {
                $decision = $input['decision'] ?? null;
                if (! in_array($decision, ['approved', 'rejected'], true)) {
                    throw ValidationException::withMessages(['decision' => 'Pilih setujui atau tolak.']);
                }
                if ($decision === 'rejected' && mb_strlen((string) $note) < 5) {
                    throw ValidationException::withMessages(['note' => 'Alasan penolakan minimal 5 karakter.']);
                }
                if ($run->created_by === $userId && ! ($config['allow_self'] ?? false)) {
                    throw ApiException::forbidden('SELF_APPROVAL', 'Anda tidak dapat menyetujui transaksi yang Anda mulai sendiri.');
                }
                $outcome = $decision;
                $approvals = $decision === 'approved' ? [...$task->approved_by, $userId] : $task->approved_by;
                $details = ['approvals' => count($approvals), 'required' => $task->required_approvals, ...array_intersect_key($task->details, ['reasons' => true])];
                $pending = count($approvals) < $task->required_approvals;
                if ($task->assignee_type === 'asset_custodian') {
                    // Every custodian approves for their own assets; a Supervisor once for the assets without one (F08-50/51).
                    $taskDetails = $task->details;
                    if (! in_array($userId, $taskDetails['custodians'] ?? [], true)) {
                        $taskDetails['fallback_by'] = $userId;
                    }
                    $task->details = $taskDetails;
                    $pending = array_diff($taskDetails['custodians'] ?? [], $approvals) !== [] || (($taskDetails['fallback'] ?? false) && ! isset($taskDetails['fallback_by']));
                }
                if ($decision === 'approved' && $pending) {
                    // Not enough approvers yet: the step stays, the next approver is asked.
                    $task->forceFill(['approved_by' => $approvals])->save();
                    $this->step($run, $node, 'approved', $userId, $note, $details);
                    $this->audit->record('transaction.approved', $run, after: ['transaction_number' => $run->transaction_number, 'step' => $node['label'] ?? $node['id'], ...$details]);

                    return $run->refresh();
                }
                $task->forceFill(['approved_by' => $approvals]);
            } else {
                $definition = $run->definition()->with('fields')->firstOrFail();
                $keys = (array) ($config['fields'] ?? []);
                $fields = $definition->fields->whereIn('key', $keys)->values();
                $entered = $this->fieldTypes->values($fields, $input['field_values'] ?? [], $this->today(), (array) ($config['required'] ?? []), $run->id);
                $entered = array_filter($entered, fn ($v, $k) => $v !== null || array_key_exists($k, $input['field_values'] ?? []), ARRAY_FILTER_USE_BOTH);
                $run->field_values = [...$run->field_values, ...$entered];
                $run->save();
                $this->fieldTypes->claimAttachments($fields, $entered, $run->id);
                $outcome = 'submitted';
                $details = ['values' => $entered];
            }

            $task->forceFill(['status' => TransactionTask::DONE, 'completed_by' => $userId, 'completed_at' => now()])->save();
            $this->step($run, $node, $outcome, $userId, $note, $details);
            if ($task->kind === 'approval') {
                $this->audit->record("transaction.{$outcome}", $run, after: ['transaction_number' => $run->transaction_number, 'step' => $node['label'] ?? $node['id']]);
            }
            $this->moveTo($run, FlowGraph::next($graph, $node['id'], $task->kind === 'approval' ? $outcome : null));
            $this->advance($run, $graph);
            $this->audit->record('transaction.step_completed', $run, after: ['transaction_number' => $run->transaction_number, 'step' => $node['label'] ?? $node['id'], 'outcome' => $outcome]);

            return $run->refresh();
        });
    }

    /**
     * Cancelling is for the user who started the run, or a holder of transaction.reverse, and only
     * before any action changed an asset (changes made stay; record a correcting transaction).
     */
    public function cancel(TransactionRun $run, ?string $note): TransactionRun
    {
        return DB::transaction(function () use ($run, $note) {
            $run = TransactionRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! $run->isRunning()) {
                throw ApiException::conflict('RUN_ENDED', 'Transaksi ini sudah berakhir.');
            }
            $userId = $this->userId();
            if ($run->created_by !== $userId && ! $this->tenancy->tenant()->can('transaction.reverse')) {
                throw ApiException::forbidden('FORBIDDEN', 'Hanya pembuat transaksi yang dapat membatalkannya.');
            }
            if ($run->transactions()->exists()) {
                throw ApiException::conflict('RUN_HAS_CHANGES', 'Transaksi sudah mengubah aset sehingga tidak dapat dibatalkan. Catat transaksi koreksi bila perlu.');
            }
            $this->step($run, ['id' => $run->current_node_id ?? 'cancel', 'type' => 'end'], 'cancelled', $userId, $note);
            $this->finish($run, TransactionRun::CANCELLED);
            $this->audit->record('transaction_run.cancelled', $run, after: ['transaction_number' => $run->transaction_number]);

            return $run->refresh();
        });
    }

    /**
     * Moves a waiting run on when what it waits for has come (scheduled, and on demand).
     */
    public function resume(TransactionRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = TransactionRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->isRunning()) {
                $this->advance($run, $run->flowVersion->graph);
            }
        });
    }

    /**
     * After a transaction of $closing was recorded for these assets: the runs of the type it
     * closes that wait for it move on.
     *
     * @param  list<string>  $assetIds
     */
    public function resumePaired(TransactionTypeDefinition $closing, array $assetIds): void
    {
        if ($closing->closes_type_id === null || $assetIds === []) {
            return;
        }
        $runs = TransactionRun::query()->where('type_definition_id', $closing->closes_type_id)->where('state', TransactionRun::RUNNING)
            ->whereHas('assets', fn ($a) => $a->whereIn('assets.id', $assetIds))->pluck('id')->all();
        $this->resumeRuns($runs);
    }

    /**
     * The path a transaction would take, without saving anything (F-07 §4.3 "Uji alur"). People's
     * steps are assumed done; approvals take the given decision (approved by default).
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  list<string>  $assetIds
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $decisions  node id => approved|rejected
     * @return list<array{node_id: string, type: string, label: string|null, outcome: string|null}>
     */
    public function simulate(array $graph, array $assetIds, array $values, array $decisions): array
    {
        $assets = $this->scope->apply(Asset::query())->with('category:id,path')->whereKey($assetIds)->get();

        return $this->simulateWith($graph, $assets, $values, $decisions, $this->userRoles($this->userId()));
    }

    /**
     * simulate() with the assets already loaded (in the user's scope, with category), so the
     * scenario search (FlowScenarios, F-08d) can try many without loading them again.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  Collection<int, Asset>  $assets
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $decisions
     * @param  list<string>  $roles  the current user's role ids
     * @return list<array<string, mixed>>
     */
    public function simulateWith(array $graph, Collection $assets, array $values, array $decisions, array $roles): array
    {
        $path = [];
        $node = collect($graph['nodes'])->firstWhere('type', 'start');
        for ($i = 0; $node !== null && $i < self::MAX_AUTOMATIC_STEPS; $i++) {
            $config = $node['config'] ?? [];
            $refused = null;
            $outcome = match ($node['type']) {
                'start' => FlowConditions::passes($config['preconditions']['rules'] ?? [], $config['preconditions']['match'] ?? 'all', $config['preconditions']['assets'] ?? 'all', $assets, $values, $roles, $this->userId())
                    && ($refused = self::rejection($config, $assets, $values, $roles, $this->userId())) === null ? null : 'blocked',
                'condition' => FlowConditions::passes($config['rules'] ?? [], $config['match'] ?? 'all', $config['assets'] ?? 'any', $assets, $values, $roles, $this->userId()) ? 'yes' : 'no',
                'approval' => ($decisions[$node['id']] ?? 'approved') === 'rejected' ? 'rejected' : 'approved',
                default => null,
            };
            $path[] = ['node_id' => $node['id'], 'type' => $node['type'], 'label' => $node['label'] ?? null, 'outcome' => $outcome,
                ...($outcome === 'blocked' ? ['message' => $refused['message'] ?? $config['message'] ?? null, 'rule_id' => $refused['rule_id'] ?? null] : [])];
            if ($node['type'] === 'end' || $outcome === 'blocked') {
                break;
            }
            $nextId = FlowGraph::next($graph, $node['id'], in_array($node['type'], ['condition', 'approval'], true) ? $outcome : null);
            $node = $nextId === null ? null : FlowGraph::node($graph, $nextId);
        }

        return $path;
    }

    /**
     * The first rejection of the start step (a named rule "Tolak transaksi", F-08g) whose
     * conditions hold for these assets and values, if any.
     *
     * @param  array<string, mixed>  $config  of the start step
     * @param  Collection<int, Asset>  $assets
     * @param  array<string, mixed>  $values
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $core
     * @return array<string, mixed>|null
     */
    private static function rejection(array $config, Collection $assets, array $values, array $roles, ?string $starterId = null, array $core = []): ?array
    {
        foreach ((array) ($config['rejections'] ?? []) as $rejection) {
            if (is_array($rejection) && ($rejection['rules'] ?? []) !== []
                && FlowConditions::passes((array) $rejection['rules'], $rejection['match'] ?? 'all', $rejection['assets'] ?? 'any', $assets, $values, $roles, $starterId, $core)) {
                return $rejection;
            }
        }

        return null;
    }

    /** @param list<string> $runIds */
    private function resumeRuns(array $runIds): void
    {
        foreach ($runIds as $id) {
            $run = TransactionRun::query()->lockForUpdate()->find($id);
            if ($run !== null && $run->isRunning()) {
                $this->advance($run, $run->flowVersion->graph);
            }
        }
    }

    /** @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph */
    private function advance(TransactionRun $run, array $graph): void
    {
        for ($i = 0; $i < self::MAX_AUTOMATIC_STEPS; $i++) {
            if (! $run->isRunning()) {
                return;
            }
            $node = $run->current_node_id ? FlowGraph::node($graph, $run->current_node_id) : null;
            if ($node === null) {
                throw new LogicException("Run {$run->id} points at a missing step.");
            }
            $config = $node['config'] ?? [];

            switch ($node['type']) {
                case 'input':
                case 'approval':
                    // An approval nobody else has to give (the recorder is the only custodian) passes at once.
                    if (! $this->openTask($run, $node)) {
                        $this->moveTo($run, FlowGraph::next($graph, $node['id'], $node['type'] === 'approval' ? 'approved' : null));
                        break;
                    }

                    return;
                case 'condition':
                    $assets = $run->assets()->with('category:id,path')->get();
                    $yes = FlowConditions::passes($config['rules'] ?? [], $config['match'] ?? 'all', $config['assets'] ?? 'any',
                        $assets, $run->field_values, $this->userRoles($run->created_by), $run->created_by, $run->system_values ?? []);
                    $this->step($run, $node, $yes ? 'yes' : 'no');
                    $this->moveTo($run, FlowGraph::next($graph, $node['id'], $yes ? 'yes' : 'no'));
                    break;
                case 'action':
                    $applied = [];
                    foreach ($config['actions'] ?? [] as $action) {
                        $applied[] = [...$action, 'count' => $this->actions->apply($run, $action)];
                    }
                    $this->step($run, $node, 'applied', null, null, ['actions' => $applied]);
                    $this->moveTo($run, FlowGraph::next($graph, $node['id']));
                    break;
                case 'wait':
                    if (($config['until'] ?? 'date') === 'paired') {
                        if (! $this->pairRecorded($run)) {
                            return;
                        }
                        $this->step($run, $node, 'passed', null, null, ['paired' => true]);
                    } else {
                        $date = $run->field_values[$config['field'] ?? ''] ?? null;
                        if (! is_string($date) || $date > $this->today()) {
                            return;
                        }
                        $this->step($run, $node, 'passed', null, null, ['date' => $date]);
                    }
                    $this->moveTo($run, FlowGraph::next($graph, $node['id']));
                    break;
                case 'end':
                    $result = $config['result'] ?? 'completed';
                    $this->step($run, $node, $result);
                    $this->finish($run, match ($result) {
                        'rejected' => TransactionRun::REJECTED,
                        'cancelled' => TransactionRun::CANCELLED,
                        default => TransactionRun::COMPLETED,
                    });

                    return;
                default:
                    throw new LogicException("Unexpected step type {$node['type']} in a running flow.");
            }
        }
        throw new LogicException("Run {$run->id} did not stop after ".self::MAX_AUTOMATIC_STEPS.' automatic steps.');
    }

    /**
     * Has every asset of the run got a transaction of a type that closes the run's type since
     * the run started (recorded at once, or a run of that type that is not rejected or cancelled)?
     */
    private function pairRecorded(TransactionRun $run): bool
    {
        $closingTypes = TransactionTypeDefinition::query()->where('closes_type_id', $run->type_definition_id)->get(['id', 'system_type']);
        $closing = $closingTypes->pluck('id')->all();
        if ($closing === []) {
            return false;
        }
        // A closing system type records as its built-in type (an incoming closes an outgoing, F-07f-2).
        $builtIn = $closingTypes->pluck('system_type')->filter()->values()->all();
        $assetIds = DB::table('transaction_run_assets')->where('run_id', $run->id)->pluck('asset_id');
        foreach ($assetIds as $assetId) {
            $recorded = DB::table('asset_transactions')->where('asset_id', $assetId)->whereNull('run_id')->where('created_at', '>=', $run->created_at)
                ->where(fn ($q) => $q->where(fn ($c) => $c->where('type', TransactionType::CUSTOM)->whereIn('type_definition_id', $closing))
                    ->orWhereIn('type', $builtIn))->exists()
                || DB::table('transaction_run_assets as ra')->join('transaction_runs as r', 'r.id', '=', 'ra.run_id')
                    ->where('ra.asset_id', $assetId)->whereIn('r.type_definition_id', $closing)
                    ->whereIn('r.state', [TransactionRun::RUNNING, TransactionRun::COMPLETED])->where('r.created_at', '>=', $run->created_at)->exists();
            if (! $recorded) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $node */
    /**
     * Opens the task of a step done by people; false when no task is needed (a custodian approval
     * whose only custodian is the recorder, F08-51), the approval then counting as given.
     *
     * @param  array<string, mixed>  $node
     */
    private function openTask(TransactionRun $run, array $node): bool
    {
        $open = TransactionTask::query()->where('run_id', $run->id)->where('node_id', $node['id'])->where('status', TransactionTask::OPEN)->exists();
        if ($open) {
            return true;
        }
        $config = $node['config'] ?? [];
        $approval = $node['type'] === 'approval';
        $who = $approval ? ($config['approver'] ?? []) : ($config['assignee'] ?? ['type' => 'starter']);
        $required = $approval ? max(1, min(FlowGraph::MAX_APPROVALS, (int) ($config['min_approvals'] ?? 1))) : 1;
        $details = [];
        [$type, $id] = match ($who['type'] ?? 'starter') {
            'role' => ['role', $who['id']],
            'user' => ['user', $who['id']],
            'location_supervisor' => ['location_supervisor', null],
            'asset_custodian' => ['asset_custodian', null],
            'target_department_supervisor' => ['department_supervisor', null],
            'field' => ['user', is_string($run->field_values[$who['field'] ?? ''] ?? null) ? $run->field_values[$who['field']] : $run->created_by],
            default => ['user', $run->created_by],
        };
        if ($type === 'asset_custodian') {
            $details = $this->custodians($run);
            if ($details['auto_approved']) {
                $this->step($run, $node, 'approved', $run->created_by, 'Disetujui otomatis: pencatat adalah penanggung jawab.', ['auto' => true]);
            }
            if ($details['custodians'] === [] && ! $details['fallback']) {
                return false;
            }
            $required = max(1, min(FlowGraph::MAX_APPROVALS, count($details['custodians']) + ($details['fallback'] ? 1 : 0)));
        }
        if ($type === 'department_supervisor') {
            [$type, $id, $details] = $this->departmentSupervisor($run, (string) ($who['field'] ?? ''));
            $required = 1;
        }
        $days = $config['due_days'] ?? null;

        $task = new TransactionTask;
        $task->forceFill([
            'run_id' => $run->id, 'node_id' => $node['id'], 'kind' => $node['type'],
            'assignee_type' => $type, 'assignee_id' => $id, 'status' => TransactionTask::OPEN,
            'required_approvals' => $required,
            'due_date' => is_int($days) ? CarbonImmutable::parse($this->today())->addDays($days)->toDateString() : null,
            // The column holds a JSON object; an empty one is its default.
            ...($details === [] ? [] : ['details' => $details]),
        ])->save();

        return true;
    }

    /**
     * Who approves as custodian (F08-50/51): each active custodian of the run's assets once; the
     * Supervisor over the assets for assets without one or with an inactive one; the recorder's own
     * assets count as approved.
     *
     * @return array{custodians: list<string>, fallback: bool, auto_approved: bool, reasons: list<string>}
     */
    private function custodians(TransactionRun $run): array
    {
        $assets = $run->assets()->get(['assets.id', 'assets.name', 'assets.custodian_user_id']);
        $active = DB::table('organization_members')->whereIn('user_id', $assets->pluck('custodian_user_id')->filter()->unique()->values())
            ->where('status', User::STATUS_ACTIVE)->pluck('user_id')->all();
        $custodians = [];
        $reasons = [];
        $auto = false;
        foreach ($assets as $asset) {
            $custodian = $asset->custodian_user_id;
            if ($custodian === null) {
                $reasons[] = "Aset {$asset->name} belum punya penanggung jawab; persetujuan oleh Supervisor yang membawahi aset.";
            } elseif (! in_array($custodian, $active, true)) {
                $reasons[] = "Penanggung jawab aset {$asset->name} tidak aktif; persetujuan oleh Supervisor yang membawahi aset.";
            } elseif ($custodian === $run->created_by) {
                $auto = true;
            } else {
                $custodians[] = $custodian;
            }
        }

        return ['custodians' => array_values(array_unique($custodians)), 'fallback' => $reasons !== [], 'auto_approved' => $auto, 'reasons' => $reasons];
    }

    /**
     * The approvers of the receiving department (F08-52): Super Admins and Supervisors whose scope
     * covers the home department of the member in $field. Without that department, or with no such
     * approver besides the recorder, the role Super Admin approves; the reason is kept.
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function departmentSupervisor(TransactionRun $run, string $field): array
    {
        $member = is_string($run->field_values[$field] ?? null) ? $run->field_values[$field] : null;
        $department = $member === null ? null : DB::table('organization_members')->where('user_id', $member)->value('home_department_id');
        $fallback = function (string $reason): array {
            $superAdmin = Role::query()->where('template_code', RoleTemplates::SUPER_ADMIN)->orderBy('created_at')->value('id')
                ?? throw new LogicException('Organization without a Super Admin role.');

            return ['role', $superAdmin, ['reasons' => [$reason]]];
        };
        if ($department === null) {
            return $fallback('Penerima belum punya departemen asal; persetujuan oleh Super Admin.');
        }
        $approvers = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('organization_members as m', fn ($j) => $j->on('m.user_id', '=', 'ur.user_id')->where('m.status', User::STATUS_ACTIVE))
            ->whereIn('r.template_code', [RoleTemplates::SUPERVISOR, RoleTemplates::SUPER_ADMIN])
            ->where('ur.user_id', '!=', $run->created_by)
            ->whereExists(fn ($q) => $q->from('user_data_scopes as s')->whereColumn('s.user_id', 'ur.user_id')
                ->where(fn ($w) => $w->where('s.scope_type', 'organization')->orWhere(fn ($d) => $d->where('s.scope_type', 'department')->where('s.department_id', $department))))
            ->exists();
        if (! $approvers) {
            $name = Department::query()->whereKey($department)->value('name') ?? 'penerima';

            return $fallback("Tidak ada Supervisor yang cakupannya meliputi departemen {$name}; persetujuan oleh Super Admin.");
        }

        return ['department_supervisor', $department, []];
    }

    /** End the run: release the assets, close open tasks, record the completed transaction per asset. */
    private function finish(TransactionRun $run, string $state): void
    {
        $run->forceFill(['state' => $state, 'ended_at' => now()])->save();
        DB::table('transaction_run_assets')->where('run_id', $run->id)->update(['locked' => false]);
        TransactionTask::query()->where('run_id', $run->id)->where('status', TransactionTask::OPEN)->update(['status' => TransactionTask::CANCELLED]);

        // A core action already recorded the built-in transaction under the run's number (F-07f-2).
        if ($state === TransactionRun::COMPLETED && $run->definition()->first(['system_type', 'core_type'])?->coreType() === null) {
            foreach ($run->assets()->get() as $asset) {
                $this->log->record($asset, TransactionType::CUSTOM, [
                    'type_definition_id' => $run->type_definition_id, 'field_values' => $run->field_values, 'run_id' => $run->id,
                ], $run->transaction_date->toDateString(), $run->notes, $run->transaction_number);
            }
        }
        $this->audit->record("transaction_run.{$state}", $run, after: ['transaction_number' => $run->transaction_number]);
    }

    private function moveTo(TransactionRun $run, ?string $nodeId): void
    {
        if ($nodeId === null) {
            throw new LogicException("Run {$run->id} has no way forward from {$run->current_node_id}.");
        }
        $run->forceFill(['current_node_id' => $nodeId])->save();
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $details
     */
    private function step(TransactionRun $run, array $node, ?string $outcome, ?string $actorId = null, ?string $note = null, array $details = []): void
    {
        $step = new TransactionStep;
        $step->forceFill([
            'run_id' => $run->id, 'node_id' => $node['id'], 'node_type' => $node['type'],
            'outcome' => $outcome, 'actor_id' => $actorId, 'note' => $note, 'details' => $details,
        ])->save();
    }

    /** @return list<string> */
    public function userRoles(string $userId): array
    {
        return $this->access->userRoles($userId);
    }

    private function userId(): string
    {
        return $this->tenancy->tenant()->userId();
    }

    private function today(): string
    {
        return $this->log->today();
    }
}
