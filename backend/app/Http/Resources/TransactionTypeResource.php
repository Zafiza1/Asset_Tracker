<?php

namespace App\Http\Resources;

use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Models\TransactionTypeField;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TransactionTypeDefinition */
class TransactionTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'status' => $this->status,
            'multi_asset' => $this->multi_asset,
            'locks_assets' => $this->locks_assets,
            'closes_type' => $this->whenLoaded('closesType', fn () => $this->closesType ? ['id' => $this->closesType->id, 'code' => $this->closesType->code, 'name' => $this->closesType->name] : null),
            // Empty: the type applies to every category.
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()),
            'fields' => $this->whenLoaded('fields', fn () => $this->fields->map(fn (TransactionTypeField $f) => [
                'key' => $f->key,
                'label' => $f->label,
                'data_type' => $f->data_type,
                'data_type_label' => TransactionFieldTypes::TYPES[$f->data_type] ?? $f->data_type,
                'settings' => (object) ($f->settings ?? []),
                'is_required' => $f->is_required,
            ])->values()),
            'transactions_count' => $this->whenCounted('transactions'),
            // Flow versions (F-07b): the published one new transactions follow, and the draft.
            'flow' => [
                'active_version' => $this->whenLoaded('activeFlow', fn () => $this->activeFlow?->version_number),
                'draft_version' => $this->whenLoaded('draftFlow', fn () => $this->draftFlow?->version_number),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
