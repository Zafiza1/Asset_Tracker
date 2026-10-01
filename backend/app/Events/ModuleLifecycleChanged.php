<?php

namespace App\Events;

use App\Models\ProjectModule;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after every committed module lifecycle transition, carrying the
 * standard event name (project.module.installed, .configured, .enabled,
 * .disabled, .uninstalled, .upgraded — see
 * docs/architecture/events.md). Audit logging and webhooks (Phases 12/13)
 * subscribe here rather than being called from ModuleService.
 */
class ModuleLifecycleChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProjectModule $projectModule,
        public string $event,
        public ?string $previousStatus,
        public ?int $userId = null,
        public array $metadata = [],
    ) {
    }

    public function payload(): array
    {
        return [
            'event' => $this->event,
            'organization_id' => $this->projectModule->organization_id,
            'project_id' => $this->projectModule->project_id,
            'module' => $this->projectModule->module->slug,
            'version' => $this->projectModule->moduleVersion->version,
            'status' => $this->projectModule->status,
            'previous_status' => $this->previousStatus,
            'user_id' => $this->userId,
            'timestamp' => now()->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
