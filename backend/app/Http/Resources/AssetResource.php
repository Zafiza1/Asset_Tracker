<?php

namespace App\Http\Resources;

use App\Domain\Assets\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Asset */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_number' => $this->asset_number,
            'serial_number' => $this->serial_number,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => Ref::of($this->category)),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => Ref::of($this->location)),
            'condition_id' => $this->condition_id,
            'condition' => $this->whenLoaded('condition', fn () => $this->condition === null ? null : [
                ...Ref::of($this->condition), 'severity' => $this->condition->severity,
            ]),
            'status' => $this->status,
            'acquisition_date' => $this->acquisition_date?->toDateString(),
            'acquisition_cost' => $this->acquisition_cost,
            'description' => $this->description,
            'borrower_name' => $this->borrower_name,
            'loan_due_date' => $this->loan_due_date?->toDateString(),
            'is_archived' => $this->archived_at !== null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
