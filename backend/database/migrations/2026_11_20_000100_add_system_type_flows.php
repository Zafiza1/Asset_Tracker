<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-07f-2 (D-26): a system type may get a flow around its core action. A run of such a type
 * keeps what the built-in form asked (borrower, target location, status, outgoing details) in
 * system_values until the core action records the built-in transaction with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transaction_runs ADD COLUMN system_values jsonb NULL');
        DB::statement("ALTER TABLE transaction_runs ADD CONSTRAINT transaction_runs_system_values_check
            CHECK (system_values IS NULL OR jsonb_typeof(system_values) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transaction_runs DROP CONSTRAINT IF EXISTS transaction_runs_system_values_check');
        DB::statement('ALTER TABLE transaction_runs DROP COLUMN IF EXISTS system_values');
    }
};
