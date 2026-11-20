<?php

use App\Domain\AssetTransactions\SystemTransactionTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-07f-1 (D-26): the seven built-in transaction types become rows of every organization,
 * marked by system_type. One row per system type per organization; the code stays the
 * built-in prefix (organization types may not use those codes). Recording behaviour does not
 * change: a system type without a published flow records at once, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE transaction_type_definitions ADD COLUMN system_type varchar(20) NULL');
        $types = implode(',', array_map(fn ($t) => "'{$t}'", array_keys(SystemTransactionTypes::TYPES)));
        DB::statement("ALTER TABLE transaction_type_definitions ADD CONSTRAINT transaction_type_definitions_system_type_check
            CHECK (system_type IS NULL OR system_type IN ({$types}))");
        DB::statement('CREATE UNIQUE INDEX transaction_type_definitions_system_type ON transaction_type_definitions
            (organization_id, system_type) WHERE system_type IS NOT NULL');
        // A system type keeps its kind and code for good. OR REPLACE: migrate:fresh drops tables, not functions.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_system_transaction_type_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.system_type IS DISTINCT FROM NEW.system_type OR (OLD.system_type IS NOT NULL AND OLD.code IS DISTINCT FROM NEW.code) THEN
                    RAISE EXCEPTION 'system transaction types keep their kind and code' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER transaction_type_definitions_system_guard BEFORE UPDATE ON transaction_type_definitions
            FOR EACH ROW EXECUTE FUNCTION ate_system_transaction_type_guard()');

        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            SystemTransactionTypes::install($organizationId);
        }
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('DROP TRIGGER IF EXISTS transaction_type_definitions_system_guard ON transaction_type_definitions');
        DB::statement('DROP FUNCTION IF EXISTS ate_system_transaction_type_guard()');
        DB::table('transaction_type_definitions')->whereNotNull('system_type')->update(['closes_type_id' => null]);
        DB::table('transaction_type_definitions')->whereNotNull('system_type')->delete();
        DB::statement('DROP INDEX IF EXISTS transaction_type_definitions_system_type');
        DB::statement('ALTER TABLE transaction_type_definitions DROP CONSTRAINT IF EXISTS transaction_type_definitions_system_type_check');
        DB::statement('ALTER TABLE transaction_type_definitions DROP COLUMN IF EXISTS system_type');
    }
};
