<?php

namespace App\Modules\Inspection\Events;

use App\Events\WebhookTriggerable;
use App\Modules\Inspection\Models\Inspection;

/**
 * inspection.created / inspection.completed / inspection.cancelled.
 * Subscribers react to failed inspections through `result`.
 */
class InspectionChanged extends WebhookTriggerable
{
    public function __construct(Inspection $inspection, string $action)
    {
        $asset = $inspection->asset;

        parent::__construct(
            "inspection.{$action}",
            $inspection->organization_id,
            $inspection->project_id,
            [
                'inspection_id' => $inspection->id,
                'asset_id' => $asset?->id,
                'system_id' => $asset?->system_id,
                'serial_number' => $asset?->serial_number,
                'checklist_id' => $inspection->checklist_id,
                'status' => $inspection->status,
                'result' => $inspection->result,
                'failed_checks' => $inspection->result === 'fail' ? self::failedChecks($inspection) : [],
                'scheduled_at' => $inspection->scheduled_at?->toIso8601String(),
                'performed_at' => $inspection->performed_at?->toIso8601String(),
                'next_due_at' => $inspection->next_due_at?->toIso8601String(),
            ]
        );
    }

    /** Labels of the pass/fail checks that failed. */
    protected static function failedChecks(Inspection $inspection): array
    {
        return collect($inspection->checklist_snapshot ?? [])
            ->filter(fn (array $item) => $item['type'] === 'pass_fail' && ($inspection->answers[$item['key']] ?? null) === false)
            ->pluck('label')
            ->values()
            ->all();
    }
}
