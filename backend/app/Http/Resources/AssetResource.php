<?php

namespace App\Http\Resources;

use App\Domain\Assets\Models\Asset;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
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
            // The asset's "Barcode" field is on; the barcodes encode the asset number.
            'barcode_enabled' => in_array(true, array_intersect_key($this->attribute_values, array_flip(self::barcodeKeys($request))), true),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => Ref::of($this->location)),
            'status' => $this->status,
            'acquisition_date' => $this->acquisition_date?->toDateString(),
            'acquisition_cost' => $this->acquisition_cost,
            'description' => $this->description,
            // Category field values keyed by attribute key; always a JSON object.
            'attributes' => (object) $this->attribute_values,
            // The file name is random per upload, so it doubles as a cache buster.
            'image_url' => $this->image_path === null ? null
                : '/api/assets/'.$this->id.'/image?v='.pathinfo($this->image_path, PATHINFO_FILENAME),
            'document_url' => $this->document_path === null ? null : '/api/assets/'.$this->id.'/document',
            'document_name' => $this->document_name,
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

    /**
     * Looked up once per request (a list renders many assets).
     *
     * @return list<string>
     */
    private static function barcodeKeys(Request $request): array
    {
        if (! $request->attributes->has('barcode_keys')) {
            $request->attributes->set('barcode_keys', CategoryAttributeService::barcodeKeys());
        }

        return $request->attributes->get('barcode_keys');
    }
}
