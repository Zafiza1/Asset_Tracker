<?php

namespace App\Modules\Maintenance;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\Module;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\User;
use App\Modules\Maintenance\Events\MaintenanceCompleted;
use App\Modules\Maintenance\Events\MaintenanceCreated;
use App\Modules\Maintenance\Models\MaintenanceRecord;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance module business logic. Reads Core assets but never writes Core
 * tables; everything it announces goes out as maintenance.* events, which the
 * platform fans out to webhooks.
 */
class MaintenanceService
{
    public const MODULE_SLUG = 'maintenance';

    public function __construct(protected AuditService $audit)
    {
    }

    public function create(Project $project, Asset $asset, array $data, ?User $user = null): MaintenanceRecord
    {
        $this->assertAssetInProject($project, $asset);

        return DB::transaction(function () use ($project, $asset, $data, $user) {
            $record = MaintenanceRecord::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'asset_id' => $asset->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'preventive',
                'status' => 'scheduled',
                'scheduled_at' => $data['scheduled_at']
                    ?? now()->addDays($this->setting($project, 'default_interval_days', 30)),
                'notes' => $data['notes'] ?? null,
                'cost' => $data['cost'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'previous_record_id' => $data['previous_record_id'] ?? null,
                'created_by' => $user?->id,
            ]);

            $record->setRelation('asset', $asset);
            MaintenanceCreated::dispatch($record);
            $this->log('maintenance.created', $record, $user);

            return $record;
        });
    }

    /**
     * Edit details or move the record along scheduled → in_progress.
     * Completion has its own method; finished records are read-only.
     */
    public function update(MaintenanceRecord $record, array $data, ?User $user = null): MaintenanceRecord
    {
        if ($record->isFinal()) {
            throw ApiException::conflict("A {$record->status} maintenance record can no longer be changed");
        }

        if (($data['status'] ?? null) === 'in_progress' && $record->status !== 'in_progress') {
            $data['started_at'] = now();
        }

        $record->update($data);
        $this->log('maintenance.updated', $record, $user, ['changes' => array_keys($data)]);

        return $record->fresh();
    }

    /**
     * Finish a job. With the project's auto_schedule setting on, the next
     * maintenance for the asset is scheduled default_interval_days later.
     *
     * @return array{0: MaintenanceRecord, 1: MaintenanceRecord|null} the completed record and the follow-up, if any
     */
    public function complete(MaintenanceRecord $record, array $data, ?User $user = null): array
    {
        if ($record->isFinal()) {
            throw ApiException::conflict("This maintenance record is already {$record->status}");
        }

        return DB::transaction(function () use ($record, $data, $user) {
            $completedAt = isset($data['completed_at']) ? Carbon::parse($data['completed_at']) : now();

            $record->update([
                'status' => 'completed',
                'started_at' => $record->started_at ?? $completedAt,
                'completed_at' => $completedAt,
                'completed_by' => $user?->id,
                'notes' => $data['notes'] ?? $record->notes,
                'cost' => $data['cost'] ?? $record->cost,
            ]);

            MaintenanceCompleted::dispatch($record);
            $this->log('maintenance.completed', $record, $user);

            $next = null;
            $project = $record->project;
            if ($this->setting($project, 'auto_schedule', false)) {
                $next = $this->create($project, $record->asset, [
                    'title' => $record->title,
                    'description' => $record->description,
                    'type' => $record->type,
                    'scheduled_at' => $completedAt->copy()->addDays($this->setting($project, 'default_interval_days', 30)),
                    'previous_record_id' => $record->id,
                ], $user);
            }

            return [$record->fresh(), $next];
        });
    }

    /**
     * A project's configured value for a Maintenance setting (see the
     * config_schema in config/modules.php).
     */
    public function setting(Project $project, string $key, mixed $default = null): mixed
    {
        $moduleId = Module::where('slug', self::MODULE_SLUG)->value('id');

        $configuration = ProjectModule::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('module_id', $moduleId)
            ->value('configuration');

        if (is_string($configuration)) {
            $configuration = json_decode($configuration, true);
        }

        return $configuration[$key] ?? $default;
    }

    protected function assertAssetInProject(Project $project, Asset $asset): void
    {
        if ($asset->project_id !== $project->id) {
            throw ApiException::invalid('Validation failed', ['asset_id' => ['The asset does not belong to this project']]);
        }
    }

    protected function log(string $action, MaintenanceRecord $record, ?User $user, array $extra = []): void
    {
        $this->audit->logActivity(
            $action,
            'maintenance_record',
            $record->id,
            array_merge(['asset_id' => $record->asset_id, 'status' => $record->status], $extra),
            $user?->id,
            $record->organization_id,
            $record->project_id,
        );
    }
}
