<?php

use App\Domain\ReportDesigner\Catalog\FieldCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** F-09e: the asset's department and custodian as Report Designer fields. */
return new class extends Migration
{
    private const FIELDS = [
        'asset' => ['asset.department', 'asset.custodian'],
        'asset_transaction' => ['transaction.asset_department', 'transaction.asset_custodian'],
    ];

    public function up(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        FieldCatalog::sync();
    }

    public function down(): void
    {
        DB::select("SELECT set_config('app.platform_context', 'on', true)");
        foreach (self::FIELDS as $source => $keys) {
            DB::table('report_field_registry')->where('data_source', $source)->whereIn('field_key', $keys)->delete();
        }
    }
};
