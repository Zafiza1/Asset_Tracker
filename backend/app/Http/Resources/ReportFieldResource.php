<?php

namespace App\Http\Resources;

use App\Domain\ReportDesigner\Models\ReportField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportField */
class ReportFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'data_source' => $this->data_source,
            'field_key' => $this->field_key,
            'label' => $this->label,
            'data_type' => $this->data_type,
            'bind_path' => $this->bind_path,
            'relation' => $this->relation,
            'is_repeatable' => $this->is_repeatable,
            'formats' => $this->formats,
            'required_permission' => $this->required_permission,
            'sort_order' => $this->sort_order,
        ];
    }
}
