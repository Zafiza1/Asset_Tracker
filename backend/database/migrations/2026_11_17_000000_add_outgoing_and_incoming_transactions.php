<?php

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing and incoming transactions (docs/factory/features/F-02, APR-0006): assets leaving the
 * organization for an outside party, temporarily (status off_site) or permanently (disposed), and
 * temporarily outgoing assets coming back (linked to their outgoing row).
 *
 * New columns are nullable: existing rows are untouched (the table is append-only). The planned
 * return date of a temporary outgoing transaction reuses due_date (as loans do).
 */
return new class extends Migration
{
    private const OLD_STATUSES = "'available','in_use','on_loan','under_maintenance','lost','disposed'";

    private const NEW_STATUSES = "'available','in_use','on_loan','under_maintenance','lost','disposed','off_site'";

    private const OLD_TYPES = "'registration','transfer','status_change','loan','return','note','archive','restore'";

    private const NEW_TYPES = "'registration','transfer','status_change','loan','return','note','archive','restore','outgoing','incoming'";

    private const CODES = ['asset.outgoing', 'asset.incoming'];

    /** The asset group belongs to every default role template (RoleTemplates::tenant()). */
    private const ROLE_TEMPLATES = [RoleTemplates::SUPER_ADMIN, RoleTemplates::SUPERVISOR, RoleTemplates::ADMIN];

    public function up(): void
    {
        $this->statuses(self::NEW_STATUSES);
        $this->types(self::NEW_TYPES);

        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->string('outgoing_nature', 10)->nullable();
            $table->string('reason_code', 30)->nullable();
            $table->string('counterparty_name', 150)->nullable();
            $table->string('counterparty_org', 150)->nullable();
            $table->string('counterparty_contact', 150)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->uuid('outgoing_transaction_id')->nullable();
        });
        TenantSchema::tenantForeign('asset_transactions', 'outgoing_transaction_id', 'asset_transactions');

        DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_outgoing_nature_check
            CHECK (outgoing_nature IS NULL OR outgoing_nature IN ('temporary','permanent'))");
        DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_outgoing_check
            CHECK (type <> 'outgoing' OR (outgoing_nature IS NOT NULL AND reason_code IS NOT NULL AND counterparty_name IS NOT NULL))");
        DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_temporary_due_check
            CHECK (outgoing_nature IS DISTINCT FROM 'temporary' OR due_date IS NOT NULL)");
        DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_incoming_check
            CHECK (type <> 'incoming' OR outgoing_transaction_id IS NOT NULL)");
        // Finding the open outgoing row of an asset, and the "not back yet" report.
        DB::statement("CREATE INDEX asset_transactions_outgoing_idx ON asset_transactions (organization_id, asset_id, seq) WHERE type = 'outgoing'");
        DB::statement('CREATE INDEX asset_transactions_outgoing_link_idx ON asset_transactions (outgoing_transaction_id) WHERE outgoing_transaction_id IS NOT NULL');

        $this->grantPermissions();
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $permissionIds = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        DB::statement('DROP INDEX IF EXISTS asset_transactions_outgoing_link_idx');
        DB::statement('DROP INDEX IF EXISTS asset_transactions_outgoing_idx');
        foreach (['incoming_check', 'temporary_due_check', 'outgoing_check', 'outgoing_nature_check', 'outgoing_transaction_id_tfk'] as $name) {
            DB::statement("ALTER TABLE asset_transactions DROP CONSTRAINT IF EXISTS asset_transactions_{$name}");
        }
        Schema::table('asset_transactions', fn (Blueprint $table) => $table->dropColumn([
            'outgoing_nature', 'reason_code', 'counterparty_name', 'counterparty_org', 'counterparty_contact',
            'document_number', 'outgoing_transaction_id',
        ]));

        $this->types(self::OLD_TYPES);
        $this->statuses(self::OLD_STATUSES);
    }

    private function statuses(string $statuses): void
    {
        DB::statement('ALTER TABLE assets DROP CONSTRAINT assets_status_check');
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_status_check CHECK (status IN ({$statuses})) NOT VALID");
        foreach (['from_status', 'to_status'] as $column) {
            DB::statement("ALTER TABLE asset_transactions DROP CONSTRAINT asset_transactions_{$column}_check");
            DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_{$column}_check CHECK ({$column} IS NULL OR {$column} IN ({$statuses})) NOT VALID");
        }
    }

    private function types(string $types): void
    {
        DB::statement('ALTER TABLE asset_transactions DROP CONSTRAINT asset_transactions_type_check');
        DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_type_check CHECK (type IN ({$types})) NOT VALID");
    }

    private function grantPermissions(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        PermissionCatalog::sync();
        $permissions = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        $roles = DB::table('roles')->whereIn('template_code', self::ROLE_TEMPLATES)->get(['id', 'organization_id']);

        foreach ($roles as $role) {
            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'organization_id' => $role->organization_id,
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }
};
