<?php

namespace App\Http\Resources;

use App\Domain\Assets\Models\Asset;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\TenantMaster\Models\CategoryModule;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Asset */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // "No Reference (dari sistem)": the number the system gave the asset.
            'reference_number' => $this->asset_number,
            'serial_number' => $this->serial_number,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => Ref::of($this->category)),
            // Switched-on modules picked in Menu Modul for the asset's category (or a parent category).
            'modules' => $modules = self::modules($request, $this->resource),
            // Uses the Barcode module: the printable barcodes encode the asset id.
            'barcode_enabled' => in_array(OrganizationModule::BARCODE, $modules, true),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => Ref::of($this->location)),
            // F-09: who answers for the asset. A custodian who has since left stays, marked inactive.
            'custodian_user_id' => $this->custodian_user_id,
            'custodian' => $this->whenLoaded('custodianMember', fn () => $this->custodianMember === null ? null : [
                'id' => $this->custodian_user_id,
                'name' => $this->custodianMember->user?->name,
                'is_active' => $this->custodianMember->isActive() && $this->custodianMember->user?->status === User::STATUS_ACTIVE,
            ]),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => Ref::of($this->department)),
            'status' => $this->status,
            // Where a cylinder is according to its deliveries (AssetController fills it for lists and the detail).
            'position' => $request->attributes->get('asset_positions', [])[$this->id] ?? null,
            'acquisition_date' => $this->acquisition_date?->toDateString(),
            'acquisition_cost' => $this->acquisition_cost,
            'description' => $this->description,
            // Category field values keyed by attribute key; always a JSON object.
            'attributes' => (object) $this->attribute_values,
            // Optional category fields this asset does not use, and fields only it has.
            'hidden_attributes' => $this->hidden_attributes ?? [],
            'extra_attributes' => $this->extra_attributes ?? [],
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
     * The asset's modules, in catalog order. The picked categories are looked up once per
     * request (a list renders many assets).
     *
     * @return list<string>
     */
    private static function modules(Request $request, Asset $asset): array
    {
        if (! $request->attributes->has('category_modules')) {
            $request->attributes->set('category_modules', CategoryModule::activeByModule());
        }
        $active = $request->attributes->get('category_modules');
        if ($active === []) {
            return [];
        }
        $path = $asset->relationLoaded('category') ? $asset->category?->path
            : TenantAssetCategory::query()->whereKey($asset->category_id)->value('path');

        return CategoryModule::modulesForPath((string) $path, $active);
    }
}
