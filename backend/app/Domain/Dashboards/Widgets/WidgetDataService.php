<?php

namespace App\Domain\Dashboards\Widgets;

use App\Domain\Assets\AssetScope;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Widgets\Providers\AssetWidgets;
use App\Domain\Dashboards\Widgets\Providers\FlowWidgets;
use App\Domain\Dashboards\Widgets\Providers\MiscWidgets;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;

/**
 * Data of one widget on one dashboard (F-06 §4.4). The widget's catalog entry decides the
 * data permission (`required_permission`) — checked here, independently of dashboard
 * access — and every provider runs inside the member's data scope through WidgetContext.
 * One widget's failure never affects another: each widget has its own endpoint.
 */
final class WidgetDataService
{
    /** widget_key => [provider class, method]. Every catalog key must be listed. */
    private const PROVIDERS = [
        // KPI
        'total_assets' => [AssetWidgets::class, 'totalAssets'],
        'active_assets' => [AssetWidgets::class, 'activeAssets'],
        'inactive_assets' => [AssetWidgets::class, 'inactiveAssets'],
        'total_value' => [AssetWidgets::class, 'totalValue'],
        'assets_on_loan' => [AssetWidgets::class, 'assetsOnLoan'],
        'assets_off_site' => [AssetWidgets::class, 'assetsOffSite'],
        'assets_under_maintenance' => [AssetWidgets::class, 'assetsUnderMaintenance'],
        'assets_lost_damaged' => [AssetWidgets::class, 'assetsLostDamaged'],
        'data_completeness' => [AssetWidgets::class, 'dataCompleteness'],
        'pending_approvals' => [FlowWidgets::class, 'pendingApprovals'],
        // Distribusi
        'assets_by_category' => [AssetWidgets::class, 'assetsByCategory'],
        'assets_by_condition' => [AssetWidgets::class, 'assetsByCondition'],
        'assets_by_status' => [AssetWidgets::class, 'assetsByStatus'],
        'assets_by_location' => [AssetWidgets::class, 'assetsByLocation'],
        'assets_by_department' => [AssetWidgets::class, 'assetsByDepartment'],
        'value_by_category' => [AssetWidgets::class, 'valueByCategory'],
        'value_by_location' => [AssetWidgets::class, 'valueByLocation'],
        'asset_age' => [AssetWidgets::class, 'assetAge'],
        'assets_by_year' => [AssetWidgets::class, 'assetsByYear'],
        'attribute_distribution' => [AssetWidgets::class, 'attributeDistribution'],
        'attribute_summary' => [AssetWidgets::class, 'attributeSummary'],
        // Tren
        'assets_added_monthly' => [AssetWidgets::class, 'assetsAddedMonthly'],
        'transactions_monthly' => [AssetWidgets::class, 'transactionsMonthly'],
        'outgoing_vs_incoming' => [AssetWidgets::class, 'outgoingVsIncoming'],
        'cumulative_value' => [AssetWidgets::class, 'cumulativeValue'],
        // Daftar
        'recent_assets' => [AssetWidgets::class, 'recentAssets'],
        'recent_transactions' => [AssetWidgets::class, 'recentTransactions'],
        'asset_change_history' => [MiscWidgets::class, 'assetChangeHistory'],
        'date_reminders' => [AssetWidgets::class, 'dateReminders'],
        'overdue_outgoing_list' => [AssetWidgets::class, 'overdueOutgoingList'],
        'overdue_loans_list' => [AssetWidgets::class, 'overdueLoansList'],
        'due_soon_list' => [AssetWidgets::class, 'dueSoonList'],
        'incomplete_assets_list' => [AssetWidgets::class, 'incompleteAssetsList'],
        'most_transferred' => [AssetWidgets::class, 'mostTransferred'],
        'idle_assets_list' => [AssetWidgets::class, 'idleAssetsList'],
        'top_locations' => [AssetWidgets::class, 'topLocations'],
        'custom_transactions' => [AssetWidgets::class, 'customTransactions'],
        'pending_transaction_steps' => [FlowWidgets::class, 'pendingTransactionSteps'],
        // Lain-lain
        'note' => [MiscWidgets::class, 'note'],
        'shortcuts' => [MiscWidgets::class, 'shortcuts'],
    ];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetScope $scope,
        private readonly AssetWidgets $assetWidgets,
        private readonly FlowWidgets $flowWidgets,
        private readonly MiscWidgets $miscWidgets,
    ) {}

    /** @return array<string, mixed> the payload for the widget's component type */
    public function payload(DashboardWidget $widget): array
    {
        $widget->loadMissing('catalog', 'dashboard');
        $catalog = $widget->catalog;
        if ($catalog === null || ! $catalog->is_active) {
            throw ApiException::unprocessable('WIDGET_UNAVAILABLE', 'Widget tidak tersedia.');
        }
        if ($catalog->required_permission !== null && ! $this->tenancy->tenant()->can($catalog->required_permission)) {
            throw ApiException::forbidden('WIDGET_FORBIDDEN', 'Anda tidak memiliki izin untuk melihat data widget ini.');
        }
        $provider = self::PROVIDERS[$catalog->widget_key] ?? null;
        if ($provider === null) {
            throw ApiException::unprocessable('WIDGET_UNAVAILABLE', 'Widget tidak memiliki penyedia data.');
        }

        $config = WidgetConfiguration::validate($widget->configuration ?? [], $catalog->configuration_schema['fields'] ?? []);
        $context = new WidgetContext($widget->dashboard, $widget, $catalog, $config, $this->scope, $this->tenancy);

        [$class, $method] = $provider;
        $service = match ($class) {
            AssetWidgets::class => $this->assetWidgets,
            FlowWidgets::class => $this->flowWidgets,
            MiscWidgets::class => $this->miscWidgets,
        };

        return $service->{$method}($context);
    }
}
