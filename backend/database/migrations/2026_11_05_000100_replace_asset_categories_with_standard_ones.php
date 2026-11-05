<?php

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\TenantCategoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces every organization's categories with the standard ones (StandardCategories).
 * Assets are never deleted, so each moves to the standard category closest to its old one,
 * keeping the values of fields that also exist there (matched by label). Other values, the old
 * categories and their fields are removed.
 */
return new class extends Migration
{
    private const FALLBACK = 'AST_INVENTARIS';

    /** Old category code (own or of its top-level category) => standard category. */
    private const TARGETS = [
        'LAPTOP' => 'AST_IT', 'DESKTOP' => 'AST_IT', 'MONITOR' => 'AST_IT', 'PRINTER' => 'AST_IT',
        'SERVER' => 'AST_IT', 'SOFTWARE' => 'AST_IT', 'IT' => 'AST_IT',
        'MOBIL' => 'AST_KENDARAAN', 'MOTOR' => 'AST_KENDARAAN', 'TRUK' => 'AST_KENDARAAN',
        'ALAT_BERAT' => 'AST_KENDARAAN', 'KENDARAAN' => 'AST_KENDARAAN',
        'MESIN_PROD' => 'AST_MESIN', 'AC' => 'AST_MESIN',
        'KURSI' => 'AST_FURNITURE', 'MEJA' => 'AST_FURNITURE', 'LEMARI' => 'AST_FURNITURE',
        'ATK' => 'AST_FURNITURE', 'FURNITUR' => 'AST_FURNITURE',
        'TOOLS' => 'AST_ALAT_KERJA',
        'TABUNG' => 'AST_OPERASIONAL', 'TABUNG_GAS' => 'AST_OPERASIONAL',
        'ALAT_MEDIS' => 'AST_MEDIS',
        'KAMERA' => 'AST_INFRASTRUKTUR',
        'LAINNYA' => 'AST_INVENTARIS',
    ];

    /** Old default fields whose label changed. */
    private const RENAMED_LABELS = ['model' => 'model / tipe', 'kondisi' => 'kondisi aset'];

    /** Old options of the condition field => current ones. */
    private const CONDITIONS = ['GOOD' => 'BAIK', 'FAIR' => 'CUKUP', 'POOR' => 'RUSAK_RINGAN', 'DAMAGED' => 'RUSAK_BERAT'];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        app(Tenancy::class)->runAsSystem(function () {
            foreach (DB::table('organizations')->pluck('id') as $organizationId) {
                $this->replace($organizationId);
            }
        });
    }

    public function down(): void
    {
        // The old categories are gone; the standard ones stay.
    }

    private function replace(string $organizationId): void
    {
        $oldCategories = DB::table('tenant_asset_categories')->where('organization_id', $organizationId)->get(['id', 'code', 'path']);
        $oldLabels = DB::table('tenant_category_attributes')->where('organization_id', $organizationId)->pluck('label', 'key');

        // The fields go first: their keys become free for the new ones.
        DB::table('tenant_category_attributes')->where('organization_id', $organizationId)->delete();
        $standard = app(TenantCategoryService::class)->installStandard($organizationId);

        $codes = $oldCategories->pluck('code', 'id');
        $targetOf = fn (object $category) => $standard[$this->target($category, $codes->all())]->id;
        $targets = $oldCategories->mapWithKeys(fn (object $c) => [$c->id => $targetOf($c)]);

        $fields = TenantCategoryAttribute::query()->withoutGlobalScopes()->where('organization_id', $organizationId)->get()
            ->groupBy('category_id')
            ->map(fn ($attributes) => $attributes->keyBy(fn (TenantCategoryAttribute $a) => mb_strtolower($a->label)));

        $assets = DB::table('assets')->where('organization_id', $organizationId)->get(['id', 'category_id', 'attribute_values', 'lock_version']);
        foreach ($assets as $asset) {
            $target = $targets[$asset->category_id];
            DB::table('assets')->where('id', $asset->id)->update([
                'category_id' => $target,
                'attribute_values' => json_encode((object) $this->values(json_decode($asset->attribute_values, true), $oldLabels->all(), $fields[$target])),
                'lock_version' => $asset->lock_version + 1,
            ]);
        }

        foreach ($targets as $old => $new) {
            DB::table('tenant_asset_definitions')->where('tenant_category_id', $old)->update(['tenant_category_id' => $new]);
        }
        DB::table('tenant_asset_categories')->whereIn('id', $oldCategories->pluck('id'))->delete();
    }

    /** @param array<string, string> $codes old category id => code */
    private function target(object $category, array $codes): string
    {
        $root = explode('/', trim($category->path, '/'))[0];

        return self::TARGETS[$category->code] ?? self::TARGETS[$codes[$root] ?? ''] ?? self::FALLBACK;
    }

    /**
     * @param  array<string, mixed>  $values  old key => value
     * @param  array<string, string>  $oldLabels  old key => label
     * @param  \Illuminate\Support\Collection<string, TenantCategoryAttribute>  $fields  by lower case label
     * @return array<string, mixed> new key => value
     */
    private function values(array $values, array $oldLabels, $fields): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $label = mb_strtolower($oldLabels[$key] ?? '');
            $field = $fields[self::RENAMED_LABELS[$label] ?? $label] ?? null;
            if ($field === null) {
                continue;
            }
            if ($field->label === 'Kondisi Aset') {
                $value = self::CONDITIONS[$value] ?? $value;
            }
            if ($field->options === null || in_array($value, array_column($field->options, 'value'), true)) {
                $out[$field->key] = $value;
            }
        }

        return $out;
    }
};
