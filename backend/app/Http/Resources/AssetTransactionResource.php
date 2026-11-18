<?php

namespace App\Http\Resources;

use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\Services\TransactionAttachments;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetTransaction */
class AssetTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'type' => $this->type,
            // An organization's own type shows its own name (F-07).
            'type_label' => $this->type === TransactionType::CUSTOM && $this->relationLoaded('typeDefinition') && $this->typeDefinition
                ? $this->typeDefinition->name
                : TransactionType::LABELS[$this->type],
            'type_definition' => $this->when($this->type === TransactionType::CUSTOM, fn () => $this->relationLoaded('typeDefinition') && $this->typeDefinition
                ? ['id' => $this->typeDefinition->id, 'code' => $this->typeDefinition->code, 'name' => $this->typeDefinition->name]
                : ['id' => $this->type_definition_id]),
            'field_values' => $this->when($this->type === TransactionType::CUSTOM, fn () => $this->fieldValues()),
            // Rows written by a flow run (F-07b) link to it.
            'run_id' => $this->run_id,
            'transaction_date' => $this->transaction_date->toDateString(),
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id' => $this->asset->id,
                'name' => $this->asset->name,
            ]),
            'from_location' => $this->whenLoaded('fromLocation', fn () => Ref::of($this->fromLocation)),
            'to_location' => $this->whenLoaded('toLocation', fn () => Ref::of($this->toLocation)),
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'borrower_name' => $this->borrower_name,
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            // Outgoing / incoming; null on other types.
            'outgoing_nature' => $this->outgoing_nature,
            'outgoing_nature_label' => OutgoingReason::NATURE_LABELS[$this->outgoing_nature] ?? null,
            'reason_code' => $this->reason_code,
            'reason_label' => OutgoingReason::label($this->outgoing_nature, $this->reason_code),
            'counterparty_name' => $this->counterparty_name,
            'counterparty_org' => $this->counterparty_org,
            // Personal data (APR-0006 D-10): only with transaction.contact.view.
            'counterparty_contact' => self::canSeeContact() ? $this->counterparty_contact : null,
            'document_number' => $this->document_number,
            'outgoing_transaction_id' => $this->outgoing_transaction_id,
            // Temporary outgoing rows: Sudah kembali / Terlambat / Belum kembali, and days outside.
            'return_status' => $this->return_status,
            'days_out' => $this->days_out,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Entered values with their field labels, in the type's field order; values of fields removed
     * from the type since are kept at the end under their key. Outside-party contacts follow the
     * same personal-data rule as outgoing transactions.
     *
     * @return list<array{key: string, label: string, data_type: string|null, value: mixed}>
     */
    private function fieldValues(): array
    {
        $values = $this->field_values ?? [];
        $fields = $this->relationLoaded('typeDefinition') && $this->typeDefinition ? $this->typeDefinition->fields : collect();
        $display = TransactionFieldTypes::display($fields, $values);
        $files = TransactionAttachments::summaries(array_values(array_filter(
            $fields->where('data_type', 'attachment')->map(fn ($f) => $values[$f->key] ?? null)->all(), 'is_string')));
        $out = [];
        foreach ($fields as $field) {
            $value = $values[$field->key] ?? null;
            if ($field->data_type === 'attachment' && is_string($value)) {
                $value = $files[$value] ?? null;
            }
            $out[] = ['key' => $field->key, 'label' => $field->label, 'data_type' => $field->data_type, 'value' => $value, 'display' => $display[$field->key] ?? null];
            unset($values[$field->key]);
        }
        foreach ($values as $key => $value) {
            $out[] = ['key' => $key, 'label' => $key, 'data_type' => null, 'value' => $value, 'display' => is_scalar($value) ? (string) $value : null];
        }
        if (! self::canSeeContact()) {
            foreach ($out as &$item) {
                if ($item['data_type'] === 'counterparty' && is_array($item['value'])) {
                    $item['value']['contact'] = null;
                }
            }
            unset($item);
        }

        return $out;
    }

    private static function canSeeContact(): bool
    {
        $tenancy = app(Tenancy::class);

        return $tenancy->hasTenant() && $tenancy->tenant()->can('transaction.contact.view');
    }
}
