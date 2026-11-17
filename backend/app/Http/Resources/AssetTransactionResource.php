<?php

namespace App\Http\Resources;

use App\Domain\AssetTransactions\Models\AssetTransaction;
use App\Domain\AssetTransactions\OutgoingReason;
use App\Domain\AssetTransactions\TransactionType;
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
            'type_label' => TransactionType::LABELS[$this->type],
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
            'counterparty_contact' => $this->counterparty_contact,
            'document_number' => $this->document_number,
            'outgoing_transaction_id' => $this->outgoing_transaction_id,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
