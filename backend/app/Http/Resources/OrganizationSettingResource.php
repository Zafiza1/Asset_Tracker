<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\OrganizationSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationSetting */
class OrganizationSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'asset_number_format' => $this->asset_number_format,
            'transaction_number_format' => $this->transaction_number_format,
            'allow_self_approval_default' => $this->allow_self_approval_default,
            'max_upload_mb' => $this->max_upload_mb,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
