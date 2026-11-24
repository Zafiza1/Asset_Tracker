<?php

namespace App\Http\Resources;

use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\Flows\FlowGraph;
use App\Domain\AssetTransactions\Flows\RunAccess;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionStep;
use App\Domain\AssetTransactions\Models\TransactionTask;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\Services\TransactionAttachments;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\Authorization\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
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
            'type_definition' => $this->whenLoaded('definition', fn () => ['id' => $this->definition->id, 'code' => $this->definition->code, 'name' => $this->definition->name, 'system_type' => $this->definition->system_type]),
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
            // What the built-in form asked, recorded by a system type's core action (F-07f-2).
            'system_values' => $this->when($this->detail, fn () => $this->systemValues()),
            'graph' => $this->when($this->detail && $graph !== null, fn () => $graph),
            'steps' => $this->when($this->detail && $this->relationLoaded('steps'), fn () => $this->steps->map(fn (TransactionStep $s) => [
                'node_id' => $s->node_id, 'type' => $s->node_type, 'label' => $graph ? (FlowGraph::node($graph, $s->node_id)['label'] ?? null) : null,
                'outcome' => $s->outcome, 'actor' => $s->actor?->name, 'note' => $s->note, 'created_at' => $s->created_at?->toIso8601String(),
            ])->values()),
            'open_task' => $this->when($this->relationLoaded('tasks'), function () {
                $task = $this->tasks->firstWhere('status', TransactionTask::OPEN);

                return $task ? [
                    'id' => $task->id, 'node_id' => $task->node_id, 'kind' => $task->kind,
                    'assignee_type' => $task->assignee_type, 'assignee_id' => $task->assignee_id,
                    'assignee_name' => $this->assigneeName($task),
                    'due_date' => $task->due_date?->toDateString(),
                    'required_approvals' => $task->required_approvals, 'approvals' => count($task->approved_by),
                    // Why the task went to another approver than the flow named (F08-51/52).
                    'reasons' => $task->details['reasons'] ?? [],
                    // Whether the signed-in user may work this step now (the server checks again).
                    'can_act' => app(RunAccess::class)->isAssignee($task),
                ] : null;
            }),
        ];
    }

    private function assigneeName(TransactionTask $task): ?string
    {
        return match ($task->assignee_type) {
            // An approval named to someone who may no longer approve is the Super Admin's (RunAccess).
            'user' => $task->kind === 'approval' && ! app(RunAccess::class)->approvers()->where('ur.user_id', $task->assignee_id)->exists()
                ? 'Super Admin' : User::query()->whereKey($task->assignee_id)->value('name'),
            'role' => Role::query()->whereKey($task->assignee_id)->value('name'),
            'location_supervisor' => 'Atasan lokasi aset',
            'department_supervisor' => 'Supervisor departemen '.(Department::query()->whereKey($task->assignee_id)->value('name') ?? 'penerima'),
            default => null,
        };
    }

    /**
     * Values with their field labels; outside-party contacts follow transaction.contact.view.
     *
     * @return list<array{key: string, label: string, data_type: string|null, value: mixed}>
     */
    /** Labels of the built-in form's input a system type's run keeps, in the form's order. */
    private const SYSTEM_LABELS = [
        'to_location_id' => 'Lokasi tujuan', 'to_status' => 'Status baru', 'borrower_name' => 'Peminjam',
        'outgoing_nature' => 'Sifat', 'reason_code' => 'Alasan', 'counterparty_name' => 'Pihak luar',
        'counterparty_org' => 'Organisasi pihak luar', 'counterparty_contact' => 'Kontak pihak luar',
        'document_number' => 'No. dokumen', 'due_date' => 'Rencana tanggal kembali',
    ];

    /**
     * The built-in input of a system type's run, shaped like field_values so it reads the same.
     *
     * @return list<array{key: string, label: string, data_type: null, value: mixed, display: string|null}>
     */
    private function systemValues(): array
    {
        $values = $this->system_values ?? [];
        if (! app(Tenancy::class)->tenant()->can('transaction.contact.view')) {
            unset($values['counterparty_contact']);
        }
        $out = [];
        foreach (self::SYSTEM_LABELS as $key => $label) {
            if (! isset($values[$key])) {
                continue;
            }
            $value = $values[$key];
            $display = match ($key) {
                'to_location_id' => Location::query()->whereKey($value)->value('name') ?? (string) $value,
                'to_status' => AssetStatus::LABELS[$value] ?? (string) $value,
                'outgoing_nature' => OutgoingReason::NATURE_LABELS[$value] ?? (string) $value,
                'reason_code' => OutgoingReason::label($values['outgoing_nature'] ?? null, $value) ?? (string) $value,
                default => (string) $value,
            };
            $out[] = ['key' => $key, 'label' => $label, 'data_type' => null, 'value' => $value, 'display' => $display];
        }

        return $out;
    }

    private function fieldValues(): array
    {
        $values = $this->field_values;
        $fields = $this->relationLoaded('definition') ? $this->definition->fields : collect();
        $canSeeContact = app(Tenancy::class)->tenant()->can('transaction.contact.view');
        $display = TransactionFieldTypes::display($fields, $values);
        $files = TransactionAttachments::summaries(array_values(array_filter(
            $fields->where('data_type', 'attachment')->map(fn ($f) => $values[$f->key] ?? null)->all(), 'is_string')));
        $out = [];
        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;
            if ($field->data_type === 'counterparty' && is_array($value) && ! $canSeeContact) {
                $value['contact'] = null;
            }
            if ($field->data_type === 'attachment' && is_string($value)) {
                $value = $files[$value] ?? null;
            }
            $out[] = ['key' => $field->key, 'label' => $field->label, 'data_type' => $field->data_type, 'value' => $value, 'display' => $display[$field->key] ?? null];
        }

        return $out;
    }
}
