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
 * configuration_schema is declarative data, interpreted by the backend validator, never
 * code: `fields` maps a configuration key to one of these field types:
 *  - category_ids / location_ids: ids of the organization's own categories / locations
 *    (they narrow the member's data scope, never widen it);
 *  - enum: one of `options`; enum_list: any subset of `options`;
 *  - integer: between `min` and `max`; boolean; text: free text up to `max` characters;
 *  - attribute: the key (or `attribute_label`, resolved per category) of a category
 *    attribute; `data_type`/`data_types` limit which attributes are offered;
 *  - transaction_types: system transaction codes (TransactionType) and/or ids of the
 *    organization's own transaction types; empty means all;
 *  - report_template: id of one of the organization's report templates.
 * Every field may have a `default`. Keys outside `fields` are rejected.
 *
 * `permission` is the data permission a member must hold for the widget to be offered and
 * for its data to be served (NULL = any dashboard viewer). Widget data is always computed
 * inside the viewer's data scope, narrowed — never widened — by the widget's filters and,
 * on category dashboards, by the dashboard's category.
 *
 * Layout: `w` is the width in a 12-column grid (LAYOUT_WIDTHS), `h` the height in rows
 * (LAYOUT_HEIGHTS); the order on the dashboard is the widget's sort_order.
 */
final class SystemWidgets
{
    public const LAYOUT_WIDTHS = [3, 4, 6, 8, 12];

    public const LAYOUT_HEIGHTS = [1, 2, 3];

    public const COMPONENT_TYPES = ['statistic', 'chart', 'table', 'activity', 'status', 'reminder', 'location', 'note', 'shortcuts'];

    public const DATA_SOURCES = ['assets', 'transactions', 'audit_logs', 'approvals'];

    private const PERIODS = ['7d', '30d', '90d', '365d', 'all'];

    /**
     * @return array<string, array{name: string, description: string, component_type: string, data_sources: list<string>,
     *     permission?: string|null, fields: array<string, array<string, mixed>>, layout: array{w: int, h: int}}>
     */
    public static function all(): array
    {
        $categories = ['type' => 'category_ids', 'label' => 'Kategori aset'];
        $locations = ['type' => 'location_ids', 'label' => 'Lokasi'];
        $statuses = ['type' => 'enum_list', 'label' => 'Status aset', 'options' => AssetStatus::ALL];
        $limit = fn (int $default, int $max = 50): array => ['type' => 'integer', 'label' => 'Jumlah data', 'min' => 1, 'max' => $max, 'default' => $default];
        $period = ['type' => 'enum', 'label' => 'Rentang tanggal', 'options' => self::PERIODS, 'default' => '30d'];
        $chart = fn (string $default): array => ['type' => 'enum', 'label' => 'Jenis visualisasi', 'options' => ['donut', 'bar', 'line', 'list'], 'default' => $default];
        $months = fn (int $default): array => ['type' => 'integer', 'label' => 'Jumlah bulan', 'min' => 3, 'max' => 36, 'default' => $default];
        $attribute = fn (array $types, string $label = 'Atribut kategori'): array => ['type' => 'attribute', 'label' => $label, 'data_types' => $types];
        $kpi = ['w' => 3, 'h' => 1];
        $wide = ['w' => 6, 'h' => 2];
        $half = ['w' => 4, 'h' => 2];

        return [
            // ------------------------------------------------ KPI (kartu angka)
            'total_assets' => [
                'name' => 'Total Aset',
                'description' => 'Jumlah aset dalam cakupan data Anda.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => [
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'statuses' => $statuses,
                    'include_archived' => ['type' => 'boolean', 'label' => 'Termasuk aset nonaktif (arsip)', 'default' => false],
                ],
                'layout' => $kpi,
            ],
            'active_assets' => [
                'name' => 'Aset Aktif',
                'description' => 'Aset sedang dipakai: digunakan sendiri, dipinjam, atau di luar organisasi sementara.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => $kpi,
            ],
            'inactive_assets' => [
                'name' => 'Aset Tidak Aktif',
                'description' => 'Aset tidak sedang dipakai: tersedia, dalam perbaikan, hilang, atau sudah dihapuskan.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => $kpi,
            ],
            'total_value' => [
                'name' => 'Nilai Perolehan',
                'description' => 'Jumlah harga perolehan aset, dalam mata uang organisasi.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => $kpi,
            ],
            'assets_on_loan' => [
                'name' => 'Aset Dipinjam',
                'description' => 'Aset yang sedang dipinjamkan dan belum kembali.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => $kpi,
            ],
            'assets_off_site' => [
                'name' => 'Keluar Sementara',
                'description' => 'Aset di luar organisasi yang belum kembali.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => $kpi,
            ],
            'assets_under_maintenance' => [
                'name' => 'Dalam Perbaikan',
                'description' => 'Aset berstatus Dalam Perbaikan.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => $kpi,
            ],
            'assets_lost_damaged' => [
                'name' => 'Hilang / Rusak',
                'description' => 'Aset berstatus Hilang atau berkondisi rusak.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => $kpi,
            ],
            'data_completeness' => [
                'name' => 'Kelengkapan Data',
                'description' => 'Persentase isian lokasi, nomor seri, dan atribut wajib kategori yang terisi.',
                'component_type' => 'statistic',
                'data_sources' => ['assets'],
                'permission' => 'asset.update',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations],
                'layout' => $kpi,
            ],
            'pending_approvals' => [
                'name' => 'Persetujuan Menunggu',
                'description' => 'Jumlah pengajuan yang menunggu keputusan.',
                'component_type' => 'statistic',
                'data_sources' => ['approvals'],
                'permission' => 'approval.manage',
                'fields' => [],
                'layout' => $kpi,
            ],

            // ------------------------------------------- Distribusi (donut / bar / daftar)
            'assets_by_category' => [
                'name' => 'Distribusi Aset per Kategori',
                'description' => 'Jumlah aset pada setiap kategori.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['location_ids' => $locations, 'statuses' => $statuses, 'visualization' => $chart('bar'), 'limit' => $limit(8, 20)],
                'layout' => $wide,
            ],
            'assets_by_condition' => [
                'name' => 'Distribusi Kondisi Aset',
                'description' => 'Jumlah aset per nilai atribut Kondisi Aset.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'visualization' => $chart('donut')],
                'layout' => $half,
            ],
            'assets_by_status' => [
                'name' => 'Status Aset',
                'description' => 'Jumlah aset per status.',
                'component_type' => 'status',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'visualization' => $chart('donut')],
                'layout' => $half,
            ],
            'assets_by_location' => [
                'name' => 'Aset per Lokasi',
                'description' => 'Jumlah aset pada setiap lokasi.',
                'component_type' => 'location',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'statuses' => $statuses, 'limit' => $limit(8, 20)],
                'layout' => $half,
            ],
            'value_by_category' => [
                'name' => 'Nilai per Kategori',
                'description' => 'Jumlah harga perolehan per kategori, bukan jumlah unit.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['location_ids' => $locations, 'statuses' => $statuses, 'visualization' => $chart('bar'), 'limit' => $limit(8, 20)],
                'layout' => $wide,
            ],
            'value_by_location' => [
                'name' => 'Nilai per Lokasi',
                'description' => 'Jumlah harga perolehan per lokasi.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'statuses' => $statuses, 'visualization' => $chart('bar'), 'limit' => $limit(8, 20)],
                'layout' => $wide,
            ],
            'asset_age' => [
                'name' => 'Umur Aset',
                'description' => 'Kelompok umur aset sejak tanggal perolehan.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'visualization' => $chart('bar')],
                'layout' => $half,
            ],
            'assets_by_year' => [
                'name' => 'Tahun Perolehan',
                'description' => 'Jumlah dan nilai perolehan aset per tahun perolehan.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'visualization' => $chart('bar'), 'limit' => $limit(10, 30)],
                'layout' => $wide,
            ],
            'attribute_distribution' => [
                'name' => 'Distribusi Nilai Atribut',
                'description' => 'Jumlah aset per nilai sebuah atribut kategori (mis. merek, jenis BBM).',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => [
                    'attribute' => $attribute(['enum', 'string', 'text'], 'Atribut yang dihitung'),
                    'attribute_label' => $attribute(['enum', 'string', 'text'], 'Label atribut (template lintas dashboard)'),
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'visualization' => $chart('bar'),
                    'limit' => $limit(10, 20),
                ],
                'layout' => $wide,
            ],
            'attribute_summary' => [
                'name' => 'Ringkasan Atribut Angka',
                'description' => 'Jumlah, rata-rata, minimum, dan maksimum sebuah atribut angka (mis. kapasitas mesin).',
                'component_type' => 'table',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => [
                    'attribute' => $attribute(['integer', 'decimal'], 'Atribut angka'),
                    'attribute_label' => $attribute(['integer', 'decimal'], 'Label atribut (template lintas dashboard)'),
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                ],
                'layout' => $wide,
            ],

            // ---------------------------------------------------------------- Tren
            'assets_added_monthly' => [
                'name' => 'Penambahan Aset per Bulan',
                'description' => 'Aset yang didaftarkan per bulan.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => [
                    'months' => $months(12),
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'visualization' => $chart('line'),
                ],
                'layout' => $wide,
            ],
            'transactions_monthly' => [
                'name' => 'Transaksi per Bulan per Jenis',
                'description' => 'Jumlah transaksi per bulan untuk setiap jenis, termasuk jenis buatan organisasi.',
                'component_type' => 'chart',
                'data_sources' => ['transactions'],
                'permission' => 'transaction.view',
                'fields' => [
                    'months' => $months(6),
                    'transaction_types' => ['type' => 'transaction_types', 'label' => 'Jenis transaksi'],
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'visualization' => $chart('bar'),
                ],
                'layout' => $wide,
            ],
            'outgoing_vs_incoming' => [
                'name' => 'Keluar vs Masuk',
                'description' => 'Transaksi keluar dan masuk sementara per periode.',
                'component_type' => 'chart',
                'data_sources' => ['transactions'],
                'permission' => 'transaction.view',
                'fields' => ['period' => $period, 'category_ids' => $categories, 'location_ids' => $locations, 'visualization' => $chart('bar')],
                'layout' => $half,
            ],
            'cumulative_value' => [
                'name' => 'Nilai Aset Kumulatif',
                'description' => 'Akumulasi nilai perolehan per bulan sejak bulan pertama.',
                'component_type' => 'chart',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => [
                    'months' => $months(12),
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'visualization' => $chart('line'),
                ],
                'layout' => $wide,
            ],

            // ---------------------------------------------------------------- Daftar
            'recent_assets' => [
                'name' => 'Aset Terbaru',
                'description' => 'Aset yang terakhir didaftarkan.',
                'component_type' => 'table',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'statuses' => $statuses, 'limit' => $limit(10)],
                'layout' => $wide,
            ],
            'recent_transactions' => [
                'name' => 'Aktivitas Transaksi Terbaru',
                'description' => 'Transaksi aset terakhir.',
                'component_type' => 'activity',
                'data_sources' => ['transactions'],
                'permission' => 'transaction.view',
                'fields' => [
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'transaction_types' => ['type' => 'transaction_types', 'label' => 'Jenis transaksi'],
                    'period' => $period,
                    'limit' => $limit(10),
                ],
                'layout' => $wide,
            ],
            'asset_change_history' => [
                'name' => 'Riwayat Perubahan Aset',
                'description' => 'Perubahan data aset terakhir dari audit trail.',
                'component_type' => 'activity',
                'data_sources' => ['audit_logs'],
                'permission' => 'audit.view',
                'fields' => ['category_ids' => $categories, 'period' => $period, 'limit' => $limit(10)],
                'layout' => $wide,
            ],
            'date_reminders' => [
                'name' => 'Pengingat Tanggal Aset',
                'description' => 'Aset yang tanggalnya (mis. garansi, STNK) jatuh tempo dalam waktu dekat.',
                'component_type' => 'reminder',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => [
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'attribute' => ['type' => 'attribute', 'label' => 'Atribut tanggal', 'data_type' => 'date'],
                    'attribute_label' => ['type' => 'attribute', 'label' => 'Label atribut tanggal (template lintas dashboard)', 'data_type' => 'date'],
                    'days_ahead' => ['type' => 'integer', 'label' => 'Hari ke depan', 'min' => 1, 'max' => 365, 'default' => 30],
                    'limit' => $limit(10),
                ],
                'layout' => $wide,
            ],
            'overdue_outgoing_list' => [
                'name' => 'Keluar Sementara Lewat Batas',
                'description' => 'Aset yang belum kembali melewati rencana kembali.',
                'component_type' => 'table',
                'data_sources' => ['transactions'],
                'permission' => 'transaction.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'limit' => $limit(10)],
                'layout' => $wide,
            ],
            'overdue_loans_list' => [
                'name' => 'Peminjaman Lewat Batas',
                'description' => 'Aset yang dipinjam dan belum kembali melewati rencana kembali.',
                'component_type' => 'table',
                'data_sources' => ['assets'],
                'permission' => 'transaction.view',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'limit' => $limit(10)],
                'layout' => $wide,
            ],
            'due_soon_list' => [
                'name' => 'Jatuh Tempo Segera',
                'description' => 'Peminjaman dan keluar sementara yang akan jatuh tempo.',
                'component_type' => 'table',
                'data_sources' => ['transactions', 'assets'],
                'permission' => 'transaction.view',
                'fields' => [
                    'days_ahead' => ['type' => 'integer', 'label' => 'Hari ke depan', 'min' => 1, 'max' => 90, 'default' => 7],
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'limit' => $limit(10),
                ],
                'layout' => $wide,
            ],
            'incomplete_assets_list' => [
                'name' => 'Data Aset Belum Lengkap',
                'description' => 'Aset dan isian yang masih kosong.',
                'component_type' => 'table',
                'data_sources' => ['assets'],
                'permission' => 'asset.update',
                'fields' => ['category_ids' => $categories, 'location_ids' => $locations, 'limit' => $limit(10)],
                'layout' => $wide,
            ],
            'most_transferred' => [
                'name' => 'Aset Paling Sering Berpindah',
                'description' => 'Aset dengan transaksi pemindahan terbanyak.',
                'component_type' => 'table',
                'data_sources' => ['transactions'],
                'permission' => 'transaction.view',
                'fields' => ['period' => ['type' => 'enum', 'label' => 'Rentang tanggal', 'options' => self::PERIODS, 'default' => 'all'], 'category_ids' => $categories, 'limit' => $limit(10, 20)],
                'layout' => $half,
            ],
            'idle_assets_list' => [
                'name' => 'Aset Tanpa Aktivitas',
                'description' => 'Aset tanpa transaksi lebih dari sekian hari.',
                'component_type' => 'table',
                'data_sources' => ['assets', 'transactions'],
                'permission' => 'asset.view',
                'fields' => [
                    'days' => ['type' => 'integer', 'label' => 'Hari tanpa aktivitas', 'min' => 7, 'max' => 730, 'default' => 90],
                    'category_ids' => $categories,
                    'location_ids' => $locations,
                    'limit' => $limit(10),
                ],
                'layout' => $half,
            ],
            'top_locations' => [
                'name' => 'Lokasi Terpadat',
                'description' => 'Lokasi dengan aset terbanyak.',
                'component_type' => 'location',
                'data_sources' => ['assets'],
                'permission' => 'asset.view',
                'fields' => ['category_ids' => $categories, 'limit' => $limit(8, 20)],
                'layout' => $half,
            ],
            'custom_transactions' => [
                'name' => 'Transaksi Jenis Organisasi',
                'description' => 'Transaksi jenis buatan organisasi beserta nilai field kustomnya.',
                'component_type' => 'table',
                'data_sources' => ['transactions'],
                'permission' => 'transaction.view',
                'fields' => [
                    'transaction_types' => ['type' => 'transaction_types', 'label' => 'Jenis transaksi'],
                    'period' => $period,
                    'limit' => $limit(10),
                ],
                'layout' => $wide,
            ],
            'pending_transaction_steps' => [
                'name' => 'Langkah Transaksi Menunggu',
                'description' => 'Transaksi yang sedang berjalan dan langkah yang menunggunya.',
                'component_type' => 'table',
                'data_sources' => ['transactions'],
                'permission' => 'transaction.view',
                'fields' => ['limit' => $limit(10)],
                'layout' => $wide,
            ],

            // ------------------------------------------------------------- Lain-lain
            'note' => [
                'name' => 'Catatan',
                'description' => 'Teks bebas untuk tim, mis. jadwal stock opname.',
                'component_type' => 'note',
                'data_sources' => [],
                'permission' => null,
                'fields' => [
                    'text' => ['type' => 'text', 'label' => 'Isi catatan', 'max' => 2000],
                ],
                'layout' => $wide,
            ],
            'shortcuts' => [
                'name' => 'Pintasan',
                'description' => 'Tombol cepat ke aksi dan laporan yang sering dipakai.',
                'component_type' => 'shortcuts',
                'data_sources' => [],
                'permission' => null,
                'fields' => [
                    'report_template_id' => ['type' => 'report_template', 'label' => 'Laporan pintasan'],
                ],
                'layout' => ['w' => 4, 'h' => 1],
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
                'required_permission' => $widget['permission'] ?? null,
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
            ['name', 'description', 'component_type', 'required_permission', 'configuration_schema', 'supported_data_sources', 'default_layout', 'sort_order', 'is_active', 'updated_at']);
        DB::table('widget_catalog')->whereNotIn('widget_key', array_keys(self::all()))->update(['is_active' => false, 'updated_at' => $now]);
    }
}
