<?php

namespace App\Http\Resources;

use App\Domain\AssetTransactions\Flows\FlowGraph;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionStep;
use App\Domain\AssetTransactions\Models\TransactionTask;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transaction going through its flow (F-07 §5). The detail adds the diagram of its flow
 * version, the step history and the open task, for the "Transaksi berjalan" page.
 *
 * @mixin TransactionRun
 */
class TransactionRunResource extends JsonResource
{
    private bool $detail = false;

    public function withDetail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $graph = $this->relationLoaded('flowVersion') ? $this->flowVersion->graph : null;
        $current = $graph && $this->current_node_id ? FlowGraph::node($graph, $this->current_node_id) : null;
        $userId = app(Tenancy::class)->tenant()->userId();

        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'state' => $this->state,
            'state_label' => TransactionRun::STATE_LABELS[$this->state],
            'type_definition' => $this->whenLoaded('definition', fn () => ['id' => $this->definition->id, 'code' => $this->definition->code, 'name' => $this->definition->name]),
            'flow_version' => $this->whenLoaded('flowVersion', fn () => $this->flowVersion->version_number),
            'current_step' => $current ? ['id' => $current['id'], 'type' => $current['type'], 'label' => $current['label'] ?? null] : null,
            'assets' => $this->whenLoaded('assets', fn () => $this->assets->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'locked' => (bool) $a->pivot->locked])->values()),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('creator', fn () => ['id' => $this->creator?->id, 'name' => $this->creator?->name]),
            'is_mine' => $this->created_by === $userId,
            'created_at' => $this->created_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'field_values' => $this->when($this->detail, fn () => $this->fieldValues()),
            'graph' => $this->when($this->detail && $graph !== null, fn () => $graph),
            'steps' => $this->when($this->detail && $this->relationLoaded('steps'), fn () => $this->steps->map(fn (TransactionStep $s) => [
                'node_id' => $s->node_id, 'type' => $s->node_type, 'label' => $graph ? (FlowGraph::node($graph, $s->node_id)['label'] ?? null) : null,
                'outcome' => $s->outcome, 'actor' => $s->actor?->name, 'note' => $s->note, 'created_at' => $s->created_at?->toIso8601String(),
            ])->values()),
            'open_task' => $this->when($this->detail && $this->relationLoaded('tasks'), function () {
                $task = $this->tasks->firstWhere('status', TransactionTask::OPEN);

                return $task ? [
                    'id' => $task->id, 'node_id' => $task->node_id, 'kind' => $task->kind,
                    'assignee_type' => $task->assignee_type, 'assignee_id' => $task->assignee_id,
                    'due_date' => $task->due_date?->toDateString(),
                ] : null;
            }),
        ];
    }

    /**
     * Values with their field labels; outside-party contacts follow transaction.contact.view.
     *
     * @return list<array{key: string, label: string, data_type: string|null, value: mixed}>
     */
    private function fieldValues(): array
    {
        $values = $this->field_values;
        $fields = $this->relationLoaded('definition') ? $this->definition->fields : collect();
        $canSeeContact = app(Tenancy::class)->tenant()->can('transaction.contact.view');
        $out = [];
        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;
            if ($field->data_type === 'counterparty' && is_array($value) && ! $canSeeContact) {
                $value['contact'] = null;
            }
            $out[] = ['key' => $field->key, 'label' => $field->label, 'data_type' => $field->data_type, 'value' => $value];
        }

        return $out;
    }
}
