<?php

namespace App\Domain\Shared\Database;

use Illuminate\Support\Facades\DB;

/**
 * Migration helpers for tenant-owned tables.
 *
 * Every tenant table gets UNIQUE (organization_id, id) so children can reference it
 * through a composite foreign key, which makes cross-tenant relations impossible at the
 * database level. Row-Level Security is the third isolation layer on top of the
 * application global scope and those composite keys.
 */
final class TenantSchema
{
    /**
     * Enable and force RLS on a table whose rows are owned by `organization_id`.
     */
    public static function enableRls(string $table, bool $allowPlatformRowsInsert = false): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        if ($allowPlatformRowsInsert) {
            // Rows with organization_id NULL belong to the platform (e.g. audit of a failed
            // login for an unknown email). Only the platform context can read them.
            DB::statement("CREATE POLICY tenant_select ON {$table} FOR SELECT
                USING (organization_id = ate_current_org() OR ate_platform_context())");
            DB::statement("CREATE POLICY tenant_insert ON {$table} FOR INSERT
                WITH CHECK (organization_id IS NULL OR organization_id = ate_current_org() OR ate_platform_context())");

            return;
        }

        DB::statement("CREATE POLICY tenant_isolation ON {$table}
            USING (organization_id = ate_current_org() OR ate_platform_context())
            WITH CHECK (organization_id = ate_current_org() OR ate_platform_context())");
    }

    /**
     * Composite FK (organization_id, $column) -> $parent(organization_id, id).
     */
    public static function tenantForeign(string $table, string $column, string $parent, string $onDelete = 'RESTRICT'): void
    {
        $name = substr("{$table}_{$column}_tfk", 0, 63);
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name}
            FOREIGN KEY (organization_id, {$column}) REFERENCES {$parent} (organization_id, id) ON DELETE {$onDelete}");
    }

    public static function tenantKey(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_org_id_unique UNIQUE (organization_id, id)");
    }

    public static function forbidUpdateDelete(string $table): void
    {
        DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table}
            FOR EACH ROW EXECUTE FUNCTION ate_forbid_modification()");
    }
}
