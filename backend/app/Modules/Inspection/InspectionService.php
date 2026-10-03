<?php

namespace App\Modules\Inspection;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Modules\Inspection\Events\InspectionChanged;
use App\Modules\Inspection\Models\Inspection;
use App\Modules\Inspection\Models\InspectionChecklist;
use App\Modules\ModuleSettings;
use App\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inspection module business logic: checklists, scheduling, and recording
 * results. Reads Core assets, never writes Core tables.
 *
 * Settings (config/modules.php): checklist_required, default_interval_days.
 */
class InspectionService
{
    public const MODULE_SLUG = 'inspection';

    public function __construct(protected ModuleSettings $settings, protected AuditService $audit)
    {
    }

    // ---- Checklists -------------------------------------------------------

    public function createChecklist(Project $project, array $data, ?User $user = null): InspectionChecklist
    {
        $this->assertDistinctKeys($data['items']);

        $checklist = InspectionChecklist::create(array_merge($data, [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'active' => $data['active'] ?? true,
        ]));
        $this->log('inspection.checklist.created', 'inspection_checklist', $checklist->id, $project, $user);

        return $checklist;
    }

    /** Past inspections keep their own snapshot, so items can change freely. */
    public function updateChecklist(InspectionChecklist $checklist, array $data, ?User $user = null): InspectionChecklist
    {
        if (isset($data['items'])) {
            $this->assertDistinctKeys($data['items']);
        }

        $checklist->update($data);
        $this->log('inspection.checklist.updated', 'inspection_checklist', $checklist->id, $checklist->project, $user, ['changes' => array_keys($data)]);

        return $checklist->fresh();
    }

    // ---- Inspections ------------------------------------------------------

    /**
     * Without checklist_id, the project's active checklist for the asset's
     * type is used (a type-specific one before a generic one). Without
     * scheduled_at, the inspection is due when the asset's last inspection
     * said (next_due_at), or now.
     */
    public function schedule(Project $project, Asset $asset, array $data, ?User $user = null): Inspection
    {
        if ($asset->project_id !== $project->id) {
            throw ApiException::invalid('Validation failed', ['asset_id' => ['The asset does not belong to this project']]);
        }

        $checklist = $this->resolveChecklist($project, $asset, $data['checklist_id'] ?? null);

        $scheduledAt = $data['scheduled_at']
            ?? Inspection::where('asset_id', $asset->id)->where('status', 'completed')->latest('performed_at')->value('next_due_at')
            ?? now();

        $inspection = Inspection::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'asset_id' => $asset->id,
            'checklist_id' => $checklist?->id,
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'notes' => $data['notes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'created_by' => $user?->id,
        ]);

        $inspection->setRelation('asset', $asset);
        $this->announce($inspection, 'created', $user);

        return $inspection;
    }

    /**
     * Record the outcome. Answers are checked against the checklist, which is
     * snapshotted onto the inspection. The result is "fail" when any
     * pass/fail check fails; without a checklist it must be given.
     */
    public function record(Inspection $inspection, array $answers, array $data, ?User $user = null): Inspection
    {
        if ($inspection->status !== 'scheduled') {
            throw ApiException::conflict("A {$inspection->status} inspection cannot be recorded");
        }

        $items = $inspection->checklist?->items ?? [];
        $this->assertAnswers($items, $answers);

        $result = $items
            ? $this->resultFrom($items, $answers)
            : ($data['result'] ?? throw ApiException::invalid('Validation failed', ['result' => ['Give the result (pass or fail) for an inspection without a checklist']]));

        $performedAt = isset($data['performed_at']) ? Carbon::parse($data['performed_at']) : now();
        $interval = (int) $this->settings->get($inspection->project, self::MODULE_SLUG, 'default_interval_days', 90);

        $inspection->update([
            'status' => 'completed',
            'result' => $result,
            'performed_at' => $performedAt,
            'next_due_at' => $performedAt->copy()->addDays($interval),
            'checklist_snapshot' => $items,
            'answers' => $answers,
            'notes' => $data['notes'] ?? $inspection->notes,
            'performed_by' => $user?->id,
        ]);

        $this->announce($inspection, 'completed', $user);

        return $inspection;
    }

    /** Schedule and record in one step (an inspection done on the spot). */
    public function perform(Project $project, Asset $asset, array $answers, array $data, ?User $user = null): Inspection
    {
        return DB::transaction(function () use ($project, $asset, $answers, $data, $user) {
            $inspection = $this->schedule($project, $asset, array_merge($data, ['scheduled_at' => $data['performed_at'] ?? now()]), $user);

            return $this->record($inspection->load('checklist', 'project'), $answers, $data, $user);
        });
    }

    /** Reschedule, change the checklist or cancel — only while scheduled. */
    public function update(Inspection $inspection, array $data, ?User $user = null): Inspection
    {
        if ($inspection->status !== 'scheduled') {
            throw ApiException::conflict("A {$inspection->status} inspection can no longer be changed");
        }

        if (array_key_exists('checklist_id', $data)) {
            $data['checklist_id'] = $this->resolveChecklist($inspection->project, $inspection->asset, $data['checklist_id'])?->id;
        }

        $inspection->update($data);

        if (($data['status'] ?? null) === 'cancelled') {
            $this->announce($inspection, 'cancelled', $user);
        } else {
            $this->log('inspection.updated', 'inspection', $inspection->id, $inspection->project, $user, ['changes' => array_keys($data)]);
        }

        return $inspection->fresh();
    }

    protected function resolveChecklist(Project $project, Asset $asset, ?int $checklistId): ?InspectionChecklist
    {
        if ($checklistId !== null) {
            $checklist = InspectionChecklist::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('active', true)
                ->find($checklistId);

            if (!$checklist) {
                throw ApiException::invalid('Validation failed', ['checklist_id' => ['The checklist does not exist in this project or is inactive']]);
            }

            if ($checklist->asset_type && $checklist->asset_type !== $asset->asset_type) {
                throw ApiException::invalid('Validation failed', ['checklist_id' => ["This checklist is for {$checklist->asset_type} assets"]]);
            }

            return $checklist;
        }

        $checklist = InspectionChecklist::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('asset_type')->orWhere('asset_type', $asset->asset_type))
            ->orderByRaw('asset_type IS NULL')
            ->orderBy('id')
            ->first();

        if (!$checklist && $this->settings->get($project, self::MODULE_SLUG, 'checklist_required', true)) {
            throw ApiException::invalid('Validation failed', ['checklist_id' => ['This project requires a checklist: create one for this asset type first']]);
        }

        return $checklist;
    }

    /** Every required check answered, every answer of the right type, nothing unknown. */
    protected function assertAnswers(array $items, array $answers): void
    {
        $errors = [];
        $known = array_column($items, null, 'key');

        foreach (array_diff(array_keys($answers), array_keys($known)) as $unknown) {
            $errors["answers.{$unknown}"] = ['Not a check of this inspection\'s checklist'];
        }

        foreach ($items as $item) {
            $key = $item['key'];
            $value = $answers[$key] ?? null;

            if ($value === null || $value === '') {
                if (!empty($item['required'])) {
                    $errors["answers.{$key}"] = ["{$item['label']} is required"];
                }
                continue;
            }

            $valid = match ($item['type']) {
                'pass_fail' => is_bool($value),
                'number' => is_int($value) || is_float($value),
                'text' => is_string($value) && mb_strlen($value) <= 2000,
                default => false,
            };

            if (!$valid) {
                $errors["answers.{$key}"] = [match ($item['type']) {
                    'pass_fail' => "{$item['label']} must be true (pass) or false (fail)",
                    'number' => "{$item['label']} must be a number",
                    default => "{$item['label']} must be text (at most 2000 characters)",
                }];
            }
        }

        if ($errors) {
            throw ApiException::invalid('Validation failed', $errors);
        }
    }

    protected function resultFrom(array $items, array $answers): string
    {
        foreach ($items as $item) {
            if ($item['type'] === 'pass_fail' && ($answers[$item['key']] ?? null) === false) {
                return 'fail';
            }
        }

        return 'pass';
    }

    protected function assertDistinctKeys(array $items): void
    {
        $keys = array_column($items, 'key');

        if (count($keys) !== count(array_unique($keys))) {
            throw ApiException::invalid('Validation failed', ['items' => ['Each check needs a different key']]);
        }
    }

    protected function announce(Inspection $inspection, string $action, ?User $user): void
    {
        $inspection->loadMissing('asset');
        InspectionChanged::dispatch($inspection, $action);
        $this->log("inspection.{$action}", 'inspection', $inspection->id, $inspection->project, $user, [
            'asset_id' => $inspection->asset_id,
            'result' => $inspection->result,
        ]);
    }

    protected function log(string $action, string $resourceType, int $resourceId, Project $project, ?User $user, array $extra = []): void
    {
        $this->audit->logActivity($action, $resourceType, $resourceId, $extra ?: null, $user?->id, $project->organization_id, $project->id);
    }
}
