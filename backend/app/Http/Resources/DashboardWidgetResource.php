<?php

namespace App\Http\Resources;

use App\Domain\Dashboards\Models\DashboardWidget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DashboardWidget */
class DashboardWidgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'widget_key' => $this->whenLoaded('catalog', fn () => $this->catalog->widget_key),
            'component_type' => $this->whenLoaded('catalog', fn () => $this->catalog->component_type),
            // The custom title, else the catalog name.
            'title' => $this->title ?? $this->whenLoaded('catalog', fn () => $this->catalog->name),
            'custom_title' => $this->title,
            'configuration' => (object) $this->configuration,
            'layout' => (object) $this->layout,
            'sort_order' => $this->sort_order,
            'is_visible' => $this->is_visible,
            'is_available' => $this->whenLoaded('catalog', fn () => $this->catalog->is_active),
        ];
    }
}
