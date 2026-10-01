<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latest = $this->module->latestVersion()?->version;

        return [
            'module' => $this->module->slug,
            'name' => $this->module->name,
            'category' => $this->module->category,
            'version' => $this->moduleVersion->version,
            'latest_version' => $latest,
            'upgrade_available' => $latest !== null && version_compare($latest, $this->moduleVersion->version, '>'),
            'status' => $this->status,
            'configuration' => (object) ($this->configuration ?? []),
            'config_schema' => (object) $this->moduleVersion->configSchema(),
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'installed_at' => $this->installed_at?->toIso8601String(),
            'enabled_at' => $this->enabled_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
