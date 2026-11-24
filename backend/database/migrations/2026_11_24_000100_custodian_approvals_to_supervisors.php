<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Only Super Admin and Supervisor approve (D-57 withdrawn): approvals still open with the assets'
 * custodians go to the Supervisors over the assets, who approve afresh. Closed tasks and the run's
 * history stay as they were. Flows published with custodians as approvers are frozen; FlowEngine
 * asks the Supervisors for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::table('transaction_tasks')->where('assignee_type', 'asset_custodian')->where('status', 'open')->update([
            'assignee_type' => 'location_supervisor', 'required_approvals' => 1, 'approved_by' => '[]',
            'details' => json_encode(['reasons' => ['Penanggung jawab aset tidak lagi menyetujui; persetujuan oleh Supervisor yang membawahi aset.']]),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Who the custodians were is not kept; the Supervisors' tasks stay.
    }
};
