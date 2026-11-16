<?php

namespace App\Domain\Dashboards\Catalog;

use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\TransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single source of truth for the widget catalog, synced into `widget_catalog` by
 * DashboardCatalogSeeder. Keys are never renamed in place: dashboards reference them.
 *
 * configuration_schema is declarative data, interpreted by the backend validator (Phase 3),
 * never code: `fields` maps a configuration key to one of these field types:
 *  - category_ids / location_ids: ids of the organization's own categories / locations
 *    (they narrow the member's data scope, never widen it);
 *  - enum: one of `options`; enum_list: any subset of `options`;
 *  - integer: between `min` and `max`; boolean;
 *  - attribute: the key of a category attribute of `data_type` (resolved per organization).
 * Every field may have a `default`. Keys outside `fields` are rejected.
 *
 * Layout: `w` is the width in a 12-column grid (LAYOUT_WIDTHS), `h` the height in rows
 * (LAYOUT_HEIGHTS); the order on the dashboard is the widget's sort_order.
 */
final class SystemWidgets
{
    public const LAYOUT_WIDTHS = [3, 4, 6, 8, 12];

    public const LAYOUT_HEIGHTS = [1, 2, 3];

    public const COMPONENT_TYPES = ['statistic', 'chart', 'table', 'activity', 'status', 'reminder', 'location'];

    public const DATA_SOURCES = ['assets', 'transactions', 'audit_logs'];

    private const PERIODS = ['7d', '30d', '90d', '365d', 'all'];

    /**
     * @return array<string, array{name: string, description: string, component_type: string, data_sources: list<string>,
     *     fields: array<string, array<string, mixed>>, layout: array{w: int, h: int}}>
     */
    public static function all(): array
    {
        $categories = ['type' => 'category_ids', 'label' => 'Kategori aset'];
        $locations = ['type' => 'location_ids', 'label' => 'Lokasi'];
        $statuses = ['type' => 'enum_list', 'label' => 'Status aset', 'options' => AssetStatus::ALL];
        $limit = fn (int $default, int $max = 50): array => ['type' => 'integer', 'label' => 'Jumlah data', 'min' => 1, 'max' => $max, 'default' => $default];
        $period = ['type' => 'enum', 'label' => 'Rentang tanggal', 'options' => self::PERIODS, 'default' => '30d'];
        $chart = fn (string $default): array => ['type' => 'enum', 'label' => 'Jenis visualisasi', 'options' => ['donut', 'bar', 'list'], 'default' => $default];

        return [
            'total_assets' => [
                'name' => 'Total Aset',
                'description' => 'Jumlah aset dalam cakupan data Anda.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'fields' => [
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'statuses' => $statuses,
                    'include_archived' => ['type' => 'boolean', 'label' => 'Termasuk aset nonaktif (arsip)', 'default' => false],
                ],
                'layout' => ['w' => 3, 'h' => 1],
            ],
            'active_assets' => [
                'name' => 'Aset Aktif',
                'description' => 'Aset berstatus Aktif (sedang digunakan).',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => ['w' => 3, 'h' => 1],
            ],
            'inactive_assets' => [
                'name' => 'Aset Tidak Aktif',
                'description' => 'Aset berstatus Tidak Aktif (tidak sedang digunakan).',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => ['w' => 3, 'h' => 1],
            ],
            'assets_by_category' => [
                'name' => 'Distribusi Aset per Kategori',
                'description' => 'Jumlah aset pada setiap kategori.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'fields' => ['location_ids' => $locations, 'statuses' => $statuses, 'visualization' => $chart('bar'), 'limit' => $limit(8, 20)],
                'layout' => ['w' => 6, 'h' => 2],
            ],
            'assets_by_condition' => [
                'name' => 'Distribusi Kondisi Aset',
                'description' => 'Jumlah aset per nilai atribut Kondisi Aset.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'visualization' => $chart('donut')],
                'layout' => ['w' => 4, 'h' => 2],
            ],
            'assets_by_status' => [
                'name' => 'Status Aset',
                'description' => 'Jumlah aset per status.',
                'component_type' => 'status',
                'data_sources' => ['assets'],
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'visualization' => $chart('donut')],
                'layout' => ['w' => 4, 'h' => 2],
            ],
            'assets_by_location' => [
                'name' => 'Aset per Lokasi',
                'description' => 'Jumlah aset pada setiap lokasi.',
                'component_type' => 'location',
                'data_sources' => ['assets'],
                'fields' => ['category_ids' => $categories, 'statuses' => $statuses, 'limit' => $limit(8, 20)],
                'layout' => ['w' => 4, 'h' => 2],
            ],
            'recent_assets' => [
                'name' => 'Aset Terbaru',
                'description' => 'Aset yang terakhir didaftarkan.',
                'component_type' => 'table',
                'data_sources' => ['assets'],
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'statuses' => $statuses, 'limit' => $limit(10)],
                'layout' => ['w' => 6, 'h' => 2],
            ],
            'recent_transactions' => [
                'name' => 'Aktivitas Transaksi Terbaru',
                'description' => 'Transaksi aset terakhir.',
                'component_type' => 'activity',
                'data_sources' => ['transactions'],
                'fields' => [
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'transaction_types' => ['type' => 'enum_list', 'label' => 'Jenis transaksi', 'options' => TransactionType::ALL],
                    'period' => $period,
                    'limit' => $limit(10),
                ],
                'layout' => ['w' => 6, 'h' => 2],
            ],
            'asset_change_history' => [
                'name' => 'Riwayat Perubahan Aset',
                'description' => 'Perubahan data aset terakhir dari audit trail.',
                'component_type' => 'activity',
                'data_sources' => ['audit_logs'],
                'fields' => ['category_ids' => $categories, 'period' => $period, 'limit' => $limit(10)],
                'layout' => ['w' => 6, 'h' => 2],
            ],
            'date_reminders' => [
                'name' => 'Pengingat Tanggal Aset',
                'description' => 'Aset yang tanggalnya (mis. garansi, STNK) jatuh tempo dalam waktu dekat.',
                'component_type' => 'reminder',
                'data_sources' => ['assets'],
                'fields' => [
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'attribute' => ['type' => 'attribute', 'label' => 'Atribut tanggal', 'data_type' => 'date'],
                    'days_ahead' => ['type' => 'integer', 'label' => 'Hari ke depan', 'min' => 1, 'max' => 365, 'default' => 30],
                    'limit' => $limit(10),
                ],
                'layout' => ['w' => 6, 'h' => 2],
            ],
        ];
    }

    /** Idempotently upsert the catalog; widgets no longer defined are deactivated, never deleted. */
    public static function sync(): void
    {
        $now = now();
        $existing = DB::table('widget_catalog')->pluck('id', 'widget_key');
        $rows = [];
        $order = 0;
        foreach (self::all() as $key => $widget) {
            $rows[] = [
                'id' => $existing[$key] ?? (string) Str::uuid7(),
                'widget_key' => $key,
                'name' => $widget['name'],
                'description' => $widget['description'],
                'component_type' => $widget['component_type'],
                'configuration_schema' => json_encode(['fields' => $widget['fields']]),
                'supported_data_sources' => json_encode($widget['data_sources']),
                'default_layout' => json_encode($widget['layout']),
                'sort_order' => $order++,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('widget_catalog')->upsert($rows, ['widget_key'],
            ['name', 'description', 'component_type', 'configuration_schema', 'supported_data_sources', 'default_layout', 'sort_order', 'is_active', 'updated_at']);
        DB::table('widget_catalog')->whereNotIn('widget_key', array_keys(self::all()))->update(['is_active' => false, 'updated_at' => $now]);
    }
}
