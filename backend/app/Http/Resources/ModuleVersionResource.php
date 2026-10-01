<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModuleVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'status' => $this->status,
            'changelog' => $this->changelog,
            'dependencies' => (object) $this->dependencyMap(),
            'config_schema' => (object) $this->configSchema(),
            'permissions' => $this->permissions ?? [],
            'released_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
