<?php

use App\Domain\Shared\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom fields per asset category ("atribut kategori").
 *
 *  - tenant_category_attributes: fields an organization defines on a category; subcategories
 *    inherit the fields of their ancestors. The key is generated from the label, unique per
 *    organization (archived fields included, so a key is never reused) and, like the data type
 *    and the owning category, never changes;
 *  - assets.attribute_values: the values of an asset, keyed by attribute key (JSON object).
 */
return new class extends Migration
{
    private const TYPES = "'string', 'text', 'integer', 'decimal', 'boolean', 'date', 'enum'";

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        Schema::create('tenant_category_attributes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->uuid('category_id');
            $table->string('key', 60);
            $table->string('label', 100);
            $table->string('data_type', 16);
            $table->boolean('is_required')->default(false);
            $table->jsonb('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'category_id']);
        });
        TenantSchema::tenantKey('tenant_category_attributes');
        TenantSchema::tenantForeign('tenant_category_attributes', 'category_id', 'tenant_asset_categories');
        DB::statement('ALTER TABLE tenant_category_attributes ADD CONSTRAINT tenant_category_attributes_type_check CHECK (data_type IN ('.self::TYPES.'))');
        DB::statement("ALTER TABLE tenant_category_attributes ADD CONSTRAINT tenant_category_attributes_status_check CHECK (status IN ('active', 'archived'))");
        DB::statement("CREATE TRIGGER tenant_category_attributes_immutable BEFORE UPDATE ON tenant_category_attributes
            FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('category_id', 'key', 'data_type')");
        TenantSchema::enableRls('tenant_category_attributes');

        DB::statement("ALTER TABLE assets ADD COLUMN attribute_values jsonb NOT NULL DEFAULT '{}'::jsonb");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_attribute_values_check CHECK (jsonb_typeof(attribute_values) = 'object')");
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        DB::statement('ALTER TABLE assets DROP CONSTRAINT assets_attribute_values_check');
        DB::statement('ALTER TABLE assets DROP COLUMN attribute_values');
        Schema::dropIfExists('tenant_category_attributes');
    }
};
