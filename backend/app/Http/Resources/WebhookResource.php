<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'endpoint' => $this->endpoint,
            // The signing secret is returned once, on creation (and by
            // regenerate-secret); afterwards only a hint is exposed.
            'secret' => $this->when($this->resource->wasRecentlyCreated, $this->secret),
            'has_secret' => $this->secret !== null,
            'events' => $this->events,
            'active' => $this->active,
            'retry_policy' => $this->retry_policy,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
