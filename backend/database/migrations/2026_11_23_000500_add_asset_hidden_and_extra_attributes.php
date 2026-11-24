<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fields of one asset beyond its category: `hidden_attributes` lists the keys of optional category
 * fields that do not apply to it (RAM on a keyboard of the IT category), `extra_attributes` the
 * fields only it has ([{label, data_type, value}]). The category itself does not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE assets
            ADD COLUMN hidden_attributes jsonb NOT NULL DEFAULT '[]'::jsonb,
            ADD COLUMN extra_attributes jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_hidden_attributes_check CHECK (jsonb_typeof(hidden_attributes) = 'array')");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_extra_attributes_check CHECK (jsonb_typeof(extra_attributes) = 'array')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assets DROP COLUMN hidden_attributes, DROP COLUMN extra_attributes');
    }
};
