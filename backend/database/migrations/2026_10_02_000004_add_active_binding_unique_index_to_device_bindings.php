<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A device can have at most one active binding. DeviceService enforces this
 * in application code; the partial unique index makes it a database
 * guarantee too (PostgreSQL / SQLite support partial indexes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        // Close any duplicate active bindings left by the old silent-rebind
        // behaviour, keeping the most recent one per device.
        DB::statement(<<<'SQL'
            UPDATE device_bindings SET unbound_at = CURRENT_TIMESTAMP
            WHERE unbound_at IS NULL AND id NOT IN (
                SELECT MAX(id) FROM device_bindings WHERE unbound_at IS NULL GROUP BY device_id
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS device_bindings_active_device_unique ON device_bindings (device_id) WHERE unbound_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS device_bindings_active_device_unique');
    }
};
