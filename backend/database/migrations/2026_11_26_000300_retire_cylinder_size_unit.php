<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Satuan Ukuran is no longer a field of a cylinder (category Tabung and its subcategories). The field
 * is archived, so it leaves the form, the detail and the import template; the values already entered
 * stay in assets.attribute_values and come back if the field is restored.
 */
return new class extends Migration
{
    private const LABEL = 'Satuan Ukuran';

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $this->fields()->where('a.status', '!=', 'archived')->update(['status' => 'archived', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        $this->fields()->where('a.status', 'archived')->update(['status' => 'active', 'updated_at' => now()]);
    }

    private function fields(): Builder
    {
        return DB::table('tenant_category_attributes as a')
            ->where('a.label', self::LABEL)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('tenant_asset_categories as c')
                ->join('tenant_asset_categories as root', fn ($j) => $j->on('root.organization_id', '=', 'c.organization_id')->where('root.code', 'AST_TABUNG'))
                ->whereColumn('c.id', 'a.category_id')
                ->whereRaw("c.path LIKE root.path || '%'"));
    }
};
