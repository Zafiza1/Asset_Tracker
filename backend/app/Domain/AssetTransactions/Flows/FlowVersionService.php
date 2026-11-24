<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\AssetTransactions\Models\TransactionFlowVersion;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Drafting and publishing a transaction type's flow (F-07 §4.3): one draft at a time; publishing
 * checks the diagram (FlowGraph::issues), freezes the version and makes it the active flow.
 */
final class FlowVersionService
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    public function draft(TransactionTypeDefinition $definition): ?TransactionFlowVersion
    {
        return TransactionFlowVersion::query()->where('type_definition_id', $definition->id)
            ->where('status', TransactionFlowVersion::DRAFT)->first();
    }

    /**
     * Saves the draft. With $rules (the Designer, F-08g) the graph is the user's flow: rule steps
     * are compiled into it and the draft is a rules flow. Without (the canvas, F-07e) the graph
     * is kept as drawn and the draft's rules (else the active version's) stay for reading only.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  list<array<string, mixed>>|null  $rules
     */
    public function saveDraft(TransactionTypeDefinition $definition, array $graph, ?string $note, ?array $rules = null): TransactionFlowVersion
    {
        // A system type's flow (F-07f-2) is checked like any other; publishing needs its core action.
        return DB::transaction(function () use ($definition, $graph, $note, $rules) {
            TransactionTypeDefinition::query()->whereKey($definition->id)->lockForUpdate()->first();
            $draft = $this->draft($definition);
            if ($draft === null) {
                $draft = new TransactionFlowVersion;
                $draft->forceFill([
                    'type_definition_id' => $definition->id,
                    'version_number' => (int) TransactionFlowVersion::query()->where('type_definition_id', $definition->id)->max('version_number') + 1,
                    'status' => TransactionFlowVersion::DRAFT,
                    'created_by' => $this->tenancy->tenant()->userId(),
                ]);
            }
            if ($rules !== null) {
                $rules = array_values($rules);
                $draft->fill(['graph' => RuleCompiler::compile($graph, $rules), 'rules' => $rules, 'graph_source' => TransactionFlowVersion::SOURCE_RULES]);
            } else {
                $kept = $draft->exists ? $draft->rules
                    : (TransactionFlowVersion::query()->whereKey($definition->active_flow_version_id)->first()?->rules ?? []);
                $draft->fill(['graph' => $graph, 'rules' => $kept, 'graph_source' => TransactionFlowVersion::SOURCE_CANVAS]);
            }
            $draft->fill(['change_note' => $note])->save();

            return $draft;
        });
    }

    /**
     * What keeps a version from being published: the diagram's problems and, for a rules flow,
     * the rules' own (F-08g).
     *
     * @return list<array<string, mixed>>
     */
    public static function issues(TransactionFlowVersion $version, TransactionTypeDefinition $definition): array
    {
        $fields = $definition->relationLoaded('fields') ? $definition->fields : $definition->fields()->get();
        $rules = $version->graph_source === TransactionFlowVersion::SOURCE_RULES ? RuleCompiler::issues($version->rules, $version->graph, $fields) : [];

        return [...$rules, ...FlowGraph::issues($version->graph, $fields, $definition->id)];
    }

    public function publish(TransactionTypeDefinition $definition): TransactionFlowVersion
    {
        return DB::transaction(function () use ($definition) {
            $definition = TransactionTypeDefinition::query()->with('fields')->lockForUpdate()->findOrFail($definition->id);
            $draft = $this->draft($definition) ?? throw ApiException::conflict('NO_DRAFT', 'Belum ada draft alur untuk diterbitkan.');
            if ($draft->graph_source === TransactionFlowVersion::SOURCE_RULES) {
                // Compiled again, so the published steps match the rules exactly.
                $draft->graph = RuleCompiler::compile($draft->graph, $draft->rules);
            }
            $issues = self::issues($draft, $definition);
            if ($issues !== []) {
                throw ApiException::unprocessable('FLOW_INVALID', 'Alur masih memiliki masalah. Perbaiki sebelum diterbitkan.', ['issues' => $issues]);
            }

            $draft->forceFill(['status' => TransactionFlowVersion::PUBLISHED, 'published_at' => now(), 'published_by' => $this->tenancy->tenant()->userId()])->save();
            $definition->forceFill(['active_flow_version_id' => $draft->id])->save();
            $this->audit->record('transaction_flow.published', $definition, after: ['code' => $definition->code, 'version' => $draft->version_number, 'rules' => count($draft->rules)]);

            return $draft;
        });
    }

    /**
     * Moves every running transaction at a wait step whose date has come (scheduled daily).
     * Each organization's runs are handled in that organization's context, as the user who
     * started them.
     */
    public static function resumeWaiting(Tenancy $tenancy, FlowEngine $engine): int
    {
        $runs = $tenancy->runAsSystem(fn () => TransactionRun::query()->withoutGlobalScopes()
            ->where('state', TransactionRun::RUNNING)->get(['id', 'organization_id', 'created_by']));
        $moved = 0;
        foreach ($runs as $row) {
            [$organization, $user] = $tenancy->runAsSystem(fn () => [Organization::query()->find($row->organization_id), User::query()->find($row->created_by)]);
            if ($organization === null || $user === null) {
                continue;
            }
            $tenancy->runAsTenant(new TenantContext($organization, $user), function () use ($engine, $row, &$moved) {
                $run = TransactionRun::query()->findOrFail($row->id);
                $before = $run->current_node_id;
                $engine->resume($run);
                $moved += $run->refresh()->current_node_id !== $before || ! $run->isRunning() ? 1 : 0;
            });
        }

        return $moved;
    }
}
