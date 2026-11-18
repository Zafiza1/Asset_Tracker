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

    /** @param array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph */
    public function saveDraft(TransactionTypeDefinition $definition, array $graph, ?string $note): TransactionFlowVersion
    {
        return DB::transaction(function () use ($definition, $graph, $note) {
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
            $draft->fill(['graph' => $graph, 'change_note' => $note])->save();

            return $draft;
        });
    }

    public function publish(TransactionTypeDefinition $definition): TransactionFlowVersion
    {
        return DB::transaction(function () use ($definition) {
            $definition = TransactionTypeDefinition::query()->with('fields')->lockForUpdate()->findOrFail($definition->id);
            $draft = $this->draft($definition) ?? throw ApiException::conflict('NO_DRAFT', 'Belum ada draft alur untuk diterbitkan.');
            $issues = FlowGraph::issues($draft->graph, $definition->fields);
            if ($issues !== []) {
                throw ApiException::unprocessable('FLOW_INVALID', 'Alur masih memiliki masalah. Perbaiki sebelum diterbitkan.', ['issues' => $issues]);
            }

            $draft->forceFill(['status' => TransactionFlowVersion::PUBLISHED, 'published_at' => now(), 'published_by' => $this->tenancy->tenant()->userId()])->save();
            $definition->forceFill(['active_flow_version_id' => $draft->id])->save();
            $this->audit->record('transaction_flow.published', $definition, after: ['code' => $definition->code, 'version' => $draft->version_number]);

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
