<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The default free-text field "Penanggung Jawab" of every top-level category predates the asset's
 * own Penanggung jawab (F-09: a member of the organization), so asset forms and detail pages
 * showed two fields of that name. It is no longer a default field
 * (CategoryAttributeService::DEFAULT_ATTRIBUTES).
 *
 * Where no asset of the organization filled it in, the field is archived (its key stays
 * reserved), as the former Barcode field was. Where some did, nothing is lost: it becomes an
 * ordinary field named "Penanggung Jawab (lama)", which the organization may copy into the new
 * field and then remove.
 */
return new class extends Migration
{
    private const LABEL = 'Penanggung Jawab';

    private const KEPT_LABEL = 'Penanggung Jawab (lama)';

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $fields = DB::table('tenant_category_attributes')
            ->where('is_default', true)->where('label', self::LABEL)->where('data_type', 'string')
            ->get(['id', 'organization_id', 'key']);
        $used = $fields->filter(fn (object $field) => DB::table('assets')
            ->where('organization_id', $field->organization_id)
            ->whereRaw("coalesce(attribute_values ->> ?, '') <> ''", [$field->key])
            ->exists())->pluck('id');

        DB::table('tenant_category_attributes')->whereIn('id', $fields->pluck('id')->diff($used))
            ->update(['status' => 'archived', 'updated_at' => now()]);

        if ($used->isNotEmpty()) {
            // is_default is otherwise immutable (DB trigger).
            DB::statement('DROP TRIGGER IF EXISTS tenant_category_attributes_default_immutable ON tenant_category_attributes');
            DB::table('tenant_category_attributes')->whereIn('id', $used)
                ->update(['is_default' => false, 'label' => self::KEPT_LABEL, 'updated_at' => now()]);
            DB::statement("CREATE TRIGGER tenant_category_attributes_default_immutable BEFORE UPDATE ON tenant_category_attributes
                FOR EACH ROW EXECUTE FUNCTION ate_immutable_columns('is_default')");
        }
    }

    public function down(): void
    {
        // Fields kept as "Penanggung Jawab (lama)" may have been edited or removed since; archived ones stay archived.
    }
};
