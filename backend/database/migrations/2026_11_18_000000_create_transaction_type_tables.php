<?php

use App\Domain\Authorization\PermissionCatalog;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transaction types defined by the organization (docs/factory/features/F-07, APR-0010), phase
 * F-07a: the type, the categories it applies to and its form fields. Transactions of such a type
 * are stored in asset_transactions with type 'custom', a link to the definition and the entered
 * values (field_values). Flows, steps and tasks come with F-07b.
 *
 *  - transaction_type_definitions: code (also the number prefix), name, status
 *    (draft/active/inactive). A type that has transactions is never deleted, only deactivated.
 *  - transaction_type_categories: the categories (with their subcategories) the type applies
 *    to; none = every category.
 *  - transaction_type_fields: form fields. `key` and `data_type` never change after creation, so
 *    values already stored keep their meaning.
 *
 * Permissions transaction_type.manage and transaction_flow.manage (D-27) form their own group so
 * the Admin template does not hold them; Super Admin and Supervisor roles get them here.
 */
return new class extends Migration
{
    private const OLD_TYPES = "'registration','transfer','status_change','loan','return','note','archive','restore','outgoing','incoming'";

    private const NEW_TYPES = "'registration','transfer','status_change','loan','return','note','archive','restore','outgoing','incoming','custom'";

    private const FIELD_TYPES = "'text','textarea','number','money','date','datetime','select','multiselect','boolean','user','location','counterparty'";

    private const CODES = ['transaction_type.manage', 'transaction_flow.manage'];

    private const ROLE_TEMPLATES = [RoleTemplates::SUPER_ADMIN, RoleTemplates::SUPERVISOR];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $app = config('database.app_role');

        Schema::create('transaction_type_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->string('code', 6);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('icon', 30)->nullable();
            $table->boolean('multi_asset')->default(true);
            // Read by the flow engine (F-07b, D-29): assets wait for a running transaction to end.
            $table->boolean('locks_assets')->default(true);
            $table->uuid('closes_type_id')->nullable();
            $table->string('status', 10)->default('draft');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'code']);
        });
        TenantSchema::tenantKey('transaction_type_definitions');
        TenantSchema::tenantForeign('transaction_type_definitions', 'closes_type_id', 'transaction_type_definitions');
        TenantSchema::memberForeign('transaction_type_definitions', 'created_by');
        DB::statement("ALTER TABLE transaction_type_definitions ADD CONSTRAINT transaction_type_definitions_code_check CHECK (code ~ '^[A-Z]{2,6}$')");
        DB::statement("ALTER TABLE transaction_type_definitions ADD CONSTRAINT transaction_type_definitions_status_check CHECK (status IN ('draft','active','inactive'))");
        DB::statement('ALTER TABLE transaction_type_definitions ADD CONSTRAINT transaction_type_definitions_closes_self_check CHECK (closes_type_id IS DISTINCT FROM id)');
        TenantSchema::enableRls('transaction_type_definitions');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON transaction_type_definitions TO {$app}");

        Schema::create('transaction_type_categories', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('type_definition_id');
            $table->uuid('category_id');
            $table->primary(['type_definition_id', 'category_id']);
        });
        TenantSchema::tenantForeign('transaction_type_categories', 'type_definition_id', 'transaction_type_definitions', 'CASCADE');
        TenantSchema::tenantForeign('transaction_type_categories', 'category_id', 'tenant_asset_categories');
        TenantSchema::enableRls('transaction_type_categories');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON transaction_type_categories TO {$app}");

        Schema::create('transaction_type_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('type_definition_id');
            $table->string('key', 40);
            $table->string('label', 100);
            $table->string('data_type', 16);
            $table->jsonb('settings')->default('{}');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['type_definition_id', 'key']);
        });
        TenantSchema::tenantKey('transaction_type_fields');
        TenantSchema::tenantForeign('transaction_type_fields', 'type_definition_id', 'transaction_type_definitions', 'CASCADE');
        DB::statement("ALTER TABLE transaction_type_fields ADD CONSTRAINT transaction_type_fields_key_check CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$')");
        DB::statement('ALTER TABLE transaction_type_fields ADD CONSTRAINT transaction_type_fields_type_check CHECK (data_type IN ('.self::FIELD_TYPES.'))');
        DB::statement("ALTER TABLE transaction_type_fields ADD CONSTRAINT transaction_type_fields_settings_check CHECK (jsonb_typeof(settings) = 'object')");
        DB::statement("CREATE TRIGGER transaction_type_fields_immutable BEFORE UPDATE ON transaction_type_fields
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('type_definition_id', 'key', 'data_type')");
        TenantSchema::enableRls('transaction_type_fields');
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON transaction_type_fields TO {$app}");

        // Transactions of an organization's type: type 'custom' + the definition + entered values.
        Schema::table('asset_transactions', function (Blueprint $table) {
            $table->uuid('type_definition_id')->nullable();
            $table->jsonb('field_values')->nullable();
        });
        TenantSchema::tenantForeign('asset_transactions', 'type_definition_id', 'transaction_type_definitions');
        $this->types(self::NEW_TYPES);
        DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_custom_check
            CHECK ((type = 'custom') = (type_definition_id IS NOT NULL))");
        DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_field_values_check
            CHECK (field_values IS NULL OR jsonb_typeof(field_values) = 'object')");
        DB::statement('CREATE INDEX asset_transactions_type_definition_idx ON asset_transactions (type_definition_id) WHERE type_definition_id IS NOT NULL');

        $this->grantPermissions();
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $permissionIds = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        DB::statement('DROP INDEX IF EXISTS asset_transactions_type_definition_idx');
        foreach (['field_values_check', 'custom_check', 'type_definition_id_tfk'] as $name) {
            DB::statement("ALTER TABLE asset_transactions DROP CONSTRAINT IF EXISTS asset_transactions_{$name}");
        }
        Schema::table('asset_transactions', fn (Blueprint $table) => $table->dropColumn(['type_definition_id', 'field_values']));
        $this->types(self::OLD_TYPES);

        Schema::dropIfExists('transaction_type_fields');
        Schema::dropIfExists('transaction_type_categories');
        Schema::dropIfExists('transaction_type_definitions');
    }

    private function types(string $types): void
    {
        DB::statement('ALTER TABLE asset_transactions DROP CONSTRAINT asset_transactions_type_check');
        DB::statement("ALTER TABLE asset_transactions ADD CONSTRAINT asset_transactions_type_check CHECK (type IN ({$types})) NOT VALID");
    }

    private function grantPermissions(): void
    {
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
