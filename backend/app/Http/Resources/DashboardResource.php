<?php

namespace App\Http\Resources;

use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Services\DashboardAccessService;
use App\Domain\Dashboards\SharePermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dashboard as a container: settings, the caller's access level and, when loaded, its
 * widgets' configuration. Widget data is fetched per widget (Phase 3), so one failing widget
 * never blocks the dashboard.
 *
 * @mixin Dashboard
 */
class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $access = app(DashboardAccessService::class)->level($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'dashboard_type' => $this->dashboard_type,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'is_default' => $this->is_default,
            'category' => $this->whenLoaded('category', fn () => $this->category?->only(['id', 'code', 'name', 'parent_id', 'status'])),
            'template' => $this->whenLoaded('template', fn () => $this->template === null ? null : [
                'id' => $this->template->id,
                'name' => $this->template->name,
                'scope' => $this->template->scope,
                'version' => $this->template->version,
            ]),
            'template_version' => $this->template_version,
            'template_update_available' => $this->whenLoaded('template', fn () => $this->templateUpdateAvailable()),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'access' => $access,
            'can' => [
                'edit' => SharePermission::includes($access, SharePermission::EDIT),
                'manage' => SharePermission::includes($access, SharePermission::MANAGE),
                'delete' => $this->isCustom() && SharePermission::includes($access, SharePermission::MANAGE),
            ],
            'widgets_count' => $this->whenCounted('widgets'),
            'widgets' => DashboardWidgetResource::collection($this->whenLoaded('widgets')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
