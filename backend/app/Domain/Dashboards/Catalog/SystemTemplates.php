<?php

namespace App\Domain\Dashboards\Catalog;

use App\Domain\Dashboards\DashboardType;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\Dashboards\Models\TemplateWidget;
use App\Domain\Dashboards\Models\WidgetCatalog;
use Illuminate\Support\Facades\DB;

/**
 * System dashboard templates, synced into `dashboard_templates` (organization_id NULL) by
 * DashboardCatalogSeeder. Organizations can read and copy them, never change them.
 *
 * Category templates match a category by `category_type_key`: a StandardCategories code
 * (AST_IT, ...) or a generic category code; NULL is the generic category template every other
 * category falls back to. They never name a tenant category id.
 *
 * Changing a template's widgets requires raising its `version`: sync then replaces the stored
 * widgets, and dashboards made from an older version keep theirs until updated explicitly.
 * Widget configuration may hold `attribute_label` instead of `attribute`: attribute keys differ
 * per organization, so the label is resolved to the category's attribute when a dashboard is made.
 */
final class SystemTemplates
{
    /**
     * @return array<string, array{name: string, description: string, dashboard_type: string, category_type_key: string|null,
     *     version: int, is_default: bool, widgets: list<array{widget: string, title?: string, configuration?: array<string, mixed>, layout?: array{w: int, h: int}}>}>
     */
    public static function all(): array
    {
        $categoryWidgets = [
            ['widget' => 'total_assets'],
            ['widget' => 'active_assets'],
            ['widget' => 'inactive_assets'],
            ['widget' => 'assets_by_condition', 'layout' => ['w' => 3, 'h' => 2]],
            ['widget' => 'assets_by_status', 'layout' => ['w' => 6, 'h' => 2]],
            ['widget' => 'assets_by_location', 'layout' => ['w' => 6, 'h' => 2]],
            ['widget' => 'recent_assets'],
            ['widget' => 'recent_transactions'],
        ];
        $category = fn (string $key, string $name, string $reminderTitle, string $attributeLabel): array => [
            'name' => $name,
            'description' => "Template bawaan untuk kategori {$name}.",
            'dashboard_type' => DashboardType::CATEGORY,
            'category_type_key' => $key,
            'version' => 1,
            'is_default' => true,
            'widgets' => [
                ...$categoryWidgets,
                ['widget' => 'date_reminders', 'title' => $reminderTitle, 'configuration' => ['attribute_label' => $attributeLabel, 'days_ahead' => 30], 'layout' => ['w' => 12, 'h' => 2]],
            ],
        ];

        return [
            'overview' => [
                'name' => 'Overview',
                'description' => 'Ringkasan seluruh aset business unit.',
                'dashboard_type' => DashboardType::OVERVIEW,
                'category_type_key' => null,
                'version' => 1,
                'is_default' => true,
                'widgets' => [
                    ['widget' => 'total_assets'],
                    ['widget' => 'active_assets'],
                    ['widget' => 'inactive_assets'],
                    ['widget' => 'assets_by_status'],
                    ['widget' => 'assets_by_condition'],
                    ['widget' => 'assets_by_location'],
                    ['widget' => 'assets_by_category', 'layout' => ['w' => 12, 'h' => 2]],
                    ['widget' => 'recent_assets'],
                    ['widget' => 'recent_transactions'],
                    ['widget' => 'date_reminders', 'title' => 'Pengingat Garansi Aset', 'configuration' => ['attribute_label' => 'Tanggal Garansi Berakhir', 'days_ahead' => 30]],
                    ['widget' => 'asset_change_history'],
                ],
            ],
            'category_generic' => [
                'name' => 'Kategori Umum',
                'description' => 'Template bawaan untuk kategori yang tidak memiliki template khusus.',
                'dashboard_type' => DashboardType::CATEGORY,
                'category_type_key' => null,
                'version' => 1,
                'is_default' => true,
                'widgets' => $categoryWidgets,
            ],
            'category_it' => $category('AST_IT', 'Aset IT & Elektronik', 'Pengingat Garansi Aset', 'Tanggal Garansi Berakhir'),
            'category_vehicle' => $category('AST_KENDARAAN', 'Aset Kendaraan', 'Pengingat STNK', 'Tanggal STNK Berakhir'),
            'category_machine' => $category('AST_MESIN', 'Aset Mesin & Peralatan', 'Jadwal Maintenance', 'Jadwal Maintenance Berikutnya'),
            'category_property' => $category('AST_PROPERTI', 'Aset Bangunan & Properti', 'Pengingat Berakhirnya Hak', 'Tanggal Berakhir Hak'),
            // Kategori lain memakai widget kategori umum; yang punya atribut tanggal
            // atau enum khas mendapat widget tambahan (label, bukan key — key atribut beda per business unit).
            'category_tools' => [
                'name' => 'Aset Alat Kerja',
                'description' => 'Template bawaan untuk kategori Aset Alat Kerja.',
                'dashboard_type' => DashboardType::CATEGORY,
                'category_type_key' => 'AST_ALAT_KERJA',
                'version' => 1,
                'is_default' => true,
                'widgets' => [
                    ...$categoryWidgets,
                    ['widget' => 'attribute_distribution', 'title' => 'Jenis Alat', 'configuration' => ['attribute_label' => 'Jenis Alat', 'visualization' => 'bar'], 'layout' => ['w' => 6, 'h' => 2]],
                ],
            ],
            'category_operational' => [
                'name' => 'Aset Operasional',
                'description' => 'Template bawaan untuk kategori Aset Operasional.',
                'dashboard_type' => DashboardType::CATEGORY,
                'category_type_key' => 'AST_OPERASIONAL',
                'version' => 1,
                'is_default' => true,
                'widgets' => [
                    ...$categoryWidgets,
                    ['widget' => 'attribute_distribution', 'title' => 'Jenis Peralatan', 'configuration' => ['attribute_label' => 'Jenis Peralatan', 'visualization' => 'bar'], 'layout' => ['w' => 6, 'h' => 2]],
                    ['widget' => 'date_reminders', 'title' => 'Jadwal Kalibrasi Berikutnya', 'configuration' => ['attribute_label' => 'Jadwal Kalibrasi Berikutnya', 'days_ahead' => 30], 'layout' => ['w' => 12, 'h' => 2]],
                ],
            ],
            'category_medical' => [
                'name' => 'Aset Medis & Kesehatan',
                'description' => 'Template bawaan untuk kategori Aset Medis & Kesehatan.',
                'dashboard_type' => DashboardType::CATEGORY,
                'category_type_key' => 'AST_MEDIS',
                'version' => 1,
                'is_default' => true,
                'widgets' => [
                    ...$categoryWidgets,
                    ['widget' => 'attribute_distribution', 'title' => 'Jenis Alat Medis', 'configuration' => ['attribute_label' => 'Jenis Alat Medis', 'visualization' => 'bar'], 'layout' => ['w' => 6, 'h' => 2]],
                    ['widget' => 'date_reminders', 'title' => 'Jadwal Kalibrasi Berikutnya', 'configuration' => ['attribute_label' => 'Jadwal Kalibrasi Berikutnya', 'days_ahead' => 30], 'layout' => ['w' => 12, 'h' => 2]],
                ],
            ],
            'category_infrastructure' => [
                'name' => 'Aset Infrastruktur',
                'description' => 'Template bawaan untuk kategori Aset Infrastruktur.',
                'dashboard_type' => DashboardType::CATEGORY,
                'category_type_key' => 'AST_INFRASTRUKTUR',
                'version' => 1,
                'is_default' => true,
                'widgets' => [
                    ...$categoryWidgets,
                    ['widget' => 'attribute_distribution', 'title' => 'Jenis Infrastruktur', 'configuration' => ['attribute_label' => 'Jenis Infrastruktur', 'visualization' => 'bar'], 'layout' => ['w' => 6, 'h' => 2]],
                    ['widget' => 'date_reminders', 'title' => 'Garansi Berakhir', 'configuration' => ['attribute_label' => 'Garansi Berakhir', 'days_ahead' => 30], 'layout' => ['w' => 12, 'h' => 2]],
                ],
            ],
            'category_furniture' => [
                'name' => 'Aset Furniture & Perlengkapan',
                'description' => 'Template bawaan untuk kategori Aset Furniture & Perlengkapan.',
                'dashboard_type' => DashboardType::CATEGORY,
                'category_type_key' => 'AST_FURNITURE',
                'version' => 1,
                'is_default' => true,
                'widgets' => [
                    ...$categoryWidgets,
                    ['widget' => 'attribute_distribution', 'title' => 'Jenis Furniture', 'configuration' => ['attribute_label' => 'Jenis Furniture', 'visualization' => 'bar'], 'layout' => ['w' => 6, 'h' => 2]],
                ],
            ],
            'category_inventory' => [
                'name' => 'Aset Inventaris Umum',
                'description' => 'Template bawaan untuk kategori Aset Inventaris Umum.',
                'dashboard_type' => DashboardType::CATEGORY,
                'category_type_key' => 'AST_INVENTARIS',
                'version' => 1,
                'is_default' => true,
                'widgets' => [
                    ...$categoryWidgets,
                    ['widget' => 'attribute_distribution', 'title' => 'Jenis Inventaris', 'configuration' => ['attribute_label' => 'Jenis Inventaris', 'visualization' => 'bar'], 'layout' => ['w' => 6, 'h' => 2]],
                ],
            ],
            'custom_operations' => [
                'name' => 'Operasional Aset',
                'description' => 'Titik awal dashboard sendiri: status, aset terbaru, dan aktivitas transaksi.',
                'dashboard_type' => DashboardType::CUSTOM,
                'category_type_key' => null,
                'version' => 1,
                'is_default' => false,
                'widgets' => [
                    ['widget' => 'total_assets'],
                    ['widget' => 'active_assets'],
                    ['widget' => 'inactive_assets'],
                    ['widget' => 'assets_by_status', 'layout' => ['w' => 3, 'h' => 2]],
                    ['widget' => 'recent_transactions'],
                    ['widget' => 'recent_assets'],
                ],
            ],
            // F-06 §4.1 (D-21): three role-based asset board templates. Not defaults: the role mapping
            // of the organization copies (OrganizationProvisioner) picks one, and only one default
            // per dashboard type is allowed (dashboard_templates_default).
            'asset_board_super_admin' => [
                'name' => 'Ringkasan Eksekutif',
                'description' => 'Template Dashboard Aset untuk Super Admin: nilai dan skala aset seluruh business unit.',
                'dashboard_type' => DashboardType::ASSET_BOARD,
                'category_type_key' => null,
                'version' => 1,
                'is_default' => false,
                'widgets' => [
                    ['widget' => 'total_assets'],
                    ['widget' => 'total_value'],
                    ['widget' => 'active_assets'],
                    ['widget' => 'inactive_assets'],
                    ['widget' => 'assets_by_category', 'layout' => ['w' => 12, 'h' => 2]],
                    ['widget' => 'assets_by_location', 'layout' => ['w' => 6, 'h' => 2]],
                    ['widget' => 'assets_added_monthly', 'layout' => ['w' => 12, 'h' => 2]],
                    ['widget' => 'value_by_category', 'layout' => ['w' => 6, 'h' => 2]],
                    ['widget' => 'data_completeness'],
                    ['widget' => 'assets_lost_damaged'],
                ],
            ],
            'asset_board_supervisor' => [
                'name' => 'Pengawasan Operasional',
                'description' => 'Template Dashboard Aset untuk Supervisor: kepatuhan dan pergerakan aset.',
                'dashboard_type' => DashboardType::ASSET_BOARD,
                'category_type_key' => null,
                'version' => 1,
                'is_default' => false,
                'widgets' => [
                    ['widget' => 'assets_off_site'],
                    ['widget' => 'pending_approvals'],
                    ['widget' => 'transactions_monthly', 'layout' => ['w' => 12, 'h' => 2]],
                    ['widget' => 'outgoing_vs_incoming'],
                    ['widget' => 'assets_by_status', 'layout' => ['w' => 6, 'h' => 2]],
                    ['widget' => 'date_reminders', 'title' => 'Pengingat Umum', 'configuration' => ['attribute_label' => 'Pengingat Umum', 'days_ahead' => 30], 'layout' => ['w' => 12, 'h' => 2]],
                    ['widget' => 'most_transferred'],
                ],
            ],
            'asset_board_admin' => [
                'name' => 'Operasional Harian',
                'description' => 'Template Dashboard Aset untuk Admin: pekerjaan pencatatan sehari-hari.',
                'dashboard_type' => DashboardType::ASSET_BOARD,
                'category_type_key' => null,
                'version' => 1,
                'is_default' => false,
                'widgets' => [
                    ['widget' => 'assets_on_loan'],
                    ['widget' => 'overdue_loans_list'],
                    ['widget' => 'due_soon_list'],
                    ['widget' => 'recent_transactions'],
                    ['widget' => 'recent_assets'],
                    ['widget' => 'assets_by_condition', 'layout' => ['w' => 6, 'h' => 2]],
                    ['widget' => 'data_completeness'],
                    ['widget' => 'date_reminders', 'title' => 'Pengingat Umum', 'configuration' => ['attribute_label' => 'Pengingat Umum', 'days_ahead' => 30], 'layout' => ['w' => 12, 'h' => 2]],
                ],
            ],
        ];
    }

    /**
     * Idempotent (system context): creates missing templates, updates names and flags, and
     * replaces the widgets of a template whose version went up. Templates no longer defined are
     * deactivated, never deleted (dashboards may reference them).
     */
    public static function sync(): void
    {
        $catalog = WidgetCatalog::query()->get()->keyBy('widget_key');
        $templates = self::all();

        DB::transaction(function () use ($catalog, $templates) {
            foreach ($templates as $key => $spec) {
                $template = DashboardTemplate::query()->whereNull('organization_id')->where('template_key', $key)->first() ?? new DashboardTemplate;
                $replaceWidgets = ! $template->exists || $template->version < $spec['version'];
                $template->forceFill([
                    'organization_id' => null,
                    'template_key' => $key,
                    'name' => $spec['name'],
                    'description' => $spec['description'],
                    'dashboard_type' => $spec['dashboard_type'],
                    'category_type_key' => $spec['category_type_key'],
                    'scope' => DashboardTemplate::SCOPE_SYSTEM,
                    'version' => max($spec['version'], $template->version ?? 1),
                    'is_default' => $spec['is_default'],
                    'is_active' => true,
                ])->save();

                if (! $replaceWidgets) {
                    continue;
                }
                $template->widgets()->delete();
                foreach ($spec['widgets'] as $order => $widget) {
                    $entry = $catalog[$widget['widget']] ?? throw new \LogicException("Template {$key} uses unknown widget {$widget['widget']}.");
                    (new TemplateWidget)->forceFill([
                        'organization_id' => null,
                        'template_id' => $template->id,
                        'widget_catalog_id' => $entry->id,
                        'title' => $widget['title'] ?? null,
                        'configuration' => $widget['configuration'] ?? [],
                        'layout' => $widget['layout'] ?? $entry->default_layout,
                        'sort_order' => $order,
                    ])->save();
                }
            }

            DashboardTemplate::query()->whereNull('organization_id')->whereNotIn('template_key', array_keys($templates))
                ->update(['is_active' => false, 'is_default' => false]);
        });
    }
}
