<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-08i (D-49, D-57): two more approvers of a flow's approval step.
 *  - asset_custodian: the custodians of the run's assets; who they are, whether the Supervisor
 *    over the assets stands in for assets without an (active) custodian, and why, are kept in
 *    `details` when the task is made;
 *  - department_supervisor: Super Admins and Supervisors whose data scope covers the department
 *    named by assignee_id (the home department of the receiving member).
 * No new table; `details` also records why a task fell back to another approver (F08-51/52).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE transaction_tasks ADD COLUMN details jsonb NOT NULL DEFAULT '{}'::jsonb");
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_details_check CHECK (jsonb_typeof(details) = 'object')");
        DB::statement('ALTER TABLE transaction_tasks DROP CONSTRAINT transaction_tasks_assignee_check');
        DB::statement('ALTER TABLE transaction_tasks ALTER COLUMN assignee_type TYPE varchar(30)');
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_assignee_check CHECK (
            (assignee_type IN ('role','user') AND assignee_id IS NOT NULL)
            OR (assignee_type IN ('location_supervisor','asset_custodian') AND assignee_id IS NULL AND kind = 'approval')
            OR (assignee_type = 'department_supervisor' AND assignee_id IS NOT NULL AND kind = 'approval'))");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        DB::statement("DELETE FROM transaction_tasks WHERE assignee_type IN ('asset_custodian','department_supervisor')");
        DB::statement('ALTER TABLE transaction_tasks DROP CONSTRAINT transaction_tasks_assignee_check');
        DB::statement('ALTER TABLE transaction_tasks ALTER COLUMN assignee_type TYPE varchar(20)');
        DB::statement("ALTER TABLE transaction_tasks ADD CONSTRAINT transaction_tasks_assignee_check CHECK (
            (assignee_type IN ('role','user') AND assignee_id IS NOT NULL)
            OR (assignee_type = 'location_supervisor' AND assignee_id IS NULL AND kind = 'approval'))");
        DB::statement('ALTER TABLE transaction_tasks DROP CONSTRAINT IF EXISTS transaction_tasks_details_check');
        DB::statement('ALTER TABLE transaction_tasks DROP COLUMN IF EXISTS details');
    }
};
