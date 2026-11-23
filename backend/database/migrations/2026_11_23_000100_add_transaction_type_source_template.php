<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-08c (D-40): the ready-made template (TransactionTemplates key) an organization type was made
 * from, for its documentation and for analytics. Only a record of origin: the type is the
 * organization's own copy and never follows later changes of the template.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transaction_type_definitions ADD COLUMN source_template_key varchar(40) NULL');
        DB::statement("ALTER TABLE transaction_type_definitions ADD CONSTRAINT transaction_type_definitions_source_template_check
            CHECK (source_template_key IS NULL OR (system_type IS NULL AND source_template_key ~ '^[a-z][a-z0-9_]{0,39}$'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transaction_type_definitions DROP CONSTRAINT IF EXISTS transaction_type_definitions_source_template_check');
        DB::statement('ALTER TABLE transaction_type_definitions DROP COLUMN IF EXISTS source_template_key');
    }
};
