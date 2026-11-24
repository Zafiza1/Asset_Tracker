<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F-08g (D-46): named rules are kept with the flow version they belong to, so a running
 * transaction keeps the rules of its version (D-28); published versions stay immutable (the
 * existing trigger covers the whole row). graph_source says whether the graph is compiled from
 * the rules ('rules') or was last changed on the canvas ('canvas', F08-39); flows drawn before
 * rules existed are canvas flows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE transaction_flow_versions ADD COLUMN rules jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE transaction_flow_versions ADD COLUMN graph_source varchar(10) NOT NULL DEFAULT 'canvas'");
        DB::statement("ALTER TABLE transaction_flow_versions ADD CONSTRAINT transaction_flow_versions_graph_source_check CHECK (graph_source IN ('rules', 'canvas'))");
        DB::statement("ALTER TABLE transaction_flow_versions ADD CONSTRAINT transaction_flow_versions_rules_check CHECK (jsonb_typeof(rules) = 'array' AND jsonb_array_length(rules) <= 20)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transaction_flow_versions DROP CONSTRAINT IF EXISTS transaction_flow_versions_rules_check');
        DB::statement('ALTER TABLE transaction_flow_versions DROP CONSTRAINT IF EXISTS transaction_flow_versions_graph_source_check');
        DB::statement('ALTER TABLE transaction_flow_versions DROP COLUMN IF EXISTS graph_source');
        DB::statement('ALTER TABLE transaction_flow_versions DROP COLUMN IF EXISTS rules');
    }
};
