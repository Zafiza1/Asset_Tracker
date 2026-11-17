<?php

namespace App\Http\Resources;

use App\Domain\Dashboards\Models\WidgetCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WidgetCatalog */
class WidgetCatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'widget_key' => $this->widget_key,
            'name' => $this->name,
            'description' => $this->description,
            'component_type' => $this->component_type,
            // Declarative field list (types, options, bounds): data for the configuration form, never code.
            'configuration_schema' => (object) $this->configuration_schema,
            'supported_data_sources' => $this->supported_data_sources,
            'default_layout' => (object) $this->default_layout,
        ];
    }
}
