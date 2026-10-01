<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'author' => $this->author,
            'is_core' => $this->is_core,
            'status' => $this->status,
            'latest_version' => $this->latestVersion()?->version,
            'versions' => ModuleVersionResource::collection(
                $this->whenLoaded('versions', fn () => $this->versions
                    ->sort(fn ($a, $b) => version_compare($b->version, $a->version))
                    ->values())
            ),
        ];
    }
}
