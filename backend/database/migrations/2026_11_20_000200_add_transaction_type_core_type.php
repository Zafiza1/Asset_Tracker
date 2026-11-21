<?php

use App\Domain\AssetTransactions\SystemTransactionTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-07f/F-08 (D-26, D-40): an organization type copied from a system type keeps that type's
 * core action. core_type names the built-in transaction its core action records; it is set
 * when the copy is made and never changes. System types use system_type instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = implode(',', array_map(fn ($t) => "'{$t}'", array_keys(SystemTransactionTypes::TYPES)));
        DB::statement('ALTER TABLE transaction_type_definitions ADD COLUMN core_type varchar(20) NULL');
        DB::statement("ALTER TABLE transaction_type_definitions ADD CONSTRAINT transaction_type_definitions_core_type_check
            CHECK (core_type IS NULL OR (system_type IS NULL AND core_type IN ({$types})))");
        // The guard of F-07f-1 also keeps core_type: the copy's core rules are fixed.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_system_transaction_type_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.system_type IS DISTINCT FROM NEW.system_type OR (OLD.system_type IS NOT NULL AND OLD.code IS DISTINCT FROM NEW.code) THEN
                    RAISE EXCEPTION 'system transaction types keep their kind and code' USING ERRCODE = 'P0001';
                END IF;
                IF OLD.core_type IS DISTINCT FROM NEW.core_type THEN
                    RAISE EXCEPTION 'the core type of a transaction type never changes' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION ate_system_transaction_type_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.system_type IS DISTINCT FROM NEW.system_type OR (OLD.system_type IS NOT NULL AND OLD.code IS DISTINCT FROM NEW.code) THEN
                    RAISE EXCEPTION 'system transaction types keep their kind and code' USING ERRCODE = 'P0001';
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('ALTER TABLE transaction_type_definitions DROP CONSTRAINT IF EXISTS transaction_type_definitions_core_type_check');
        DB::statement('ALTER TABLE transaction_type_definitions DROP COLUMN IF EXISTS core_type');
    }
};
