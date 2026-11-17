<?php

namespace App\Http\Resources;

use App\Domain\Dashboards\Models\DashboardTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DashboardTemplate */
class DashboardTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_key' => $this->template_key,
            'name' => $this->name,
            'description' => $this->description,
            'dashboard_type' => $this->dashboard_type,
            'category_type_key' => $this->category_type_key,
            'scope' => $this->scope,
            'version' => $this->version,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            // System templates are copied, never edited, by organizations.
            'is_editable' => ! $this->isSystem(),
            'source_template_id' => $this->source_template_id,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'widgets_count' => $this->whenCounted('widgets'),
            'widgets' => $this->whenLoaded('widgets', fn () => $this->widgets->map(fn ($widget) => [
                'id' => $widget->id,
                'widget_key' => $widget->catalog->widget_key,
                'component_type' => $widget->catalog->component_type,
                'title' => $widget->title ?? $widget->catalog->name,
                'configuration' => (object) $widget->configuration,
                'layout' => (object) $widget->layout,
                'sort_order' => $widget->sort_order,
                'is_available' => $widget->catalog->is_active,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
