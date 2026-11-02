<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the default "Barcode" field (yes/no, Informasi Dasar, right after the other basic fields)
 * to existing top-level categories that have the default fields; new categories get it from
 * CategoryAttributeService::DEFAULT_ATTRIBUTES. A category whose own or descendants' fields
 * already use the label is skipped, since labels must stay unique along a lineage.
 */
return new class extends Migration
{
    private const LABEL = 'Barcode';

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");

        $roots = DB::table('tenant_asset_categories')->whereNull('parent_id')
            ->whereExists(fn ($q) => $q->from('tenant_category_attributes as a')
                ->whereColumn('a.category_id', 'tenant_asset_categories.id')->where('a.is_default', true))
            ->get(['id', 'organization_id', 'path']);

        foreach ($roots as $root) {
            $labelTaken = DB::table('tenant_category_attributes as a')
                ->join('tenant_asset_categories as c', 'c.id', '=', 'a.category_id')
                ->where('c.path', 'like', $root->path.'%')->where('a.status', 'active')
                ->whereRaw('lower(a.label) = ?', [mb_strtolower(self::LABEL)])->exists();
            if ($labelTaken) {
                continue;
            }

            $position = 1 + (int) DB::table('tenant_category_attributes')->where('category_id', $root->id)
                ->where('is_default', true)->where('section', 'basic_information')->max('sort_order');
            DB::table('tenant_category_attributes')->where('category_id', $root->id)
                ->where('sort_order', '>=', $position)->increment('sort_order');

            DB::table('tenant_category_attributes')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $root->organization_id,
                'category_id' => $root->id,
                'key' => $this->uniqueKey($root->organization_id),
                'label' => self::LABEL,
                'data_type' => 'boolean',
                'is_required' => false,
                'sort_order' => $position,
                'status' => 'active',
                'is_default' => true,
                'section' => 'basic_information',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Default fields are permanent (keys are never reused); the added ones stay.
    }

    /** Same scheme as CategoryAttributeService::generateKey: barcode, barcode_2, ... per organization. */
    private function uniqueKey(string $organizationId): string
    {
        $key = 'barcode';
        for ($n = 2; DB::table('tenant_category_attributes')->where('organization_id', $organizationId)->where('key', $key)->exists(); $n++) {
            $key = "barcode_{$n}";
        }

        return $key;
    }
};
