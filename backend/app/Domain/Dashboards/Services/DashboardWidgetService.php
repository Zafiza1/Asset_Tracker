<?php

namespace App\Domain\Dashboards\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Dashboards\Catalog\SystemWidgets;
use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Models\WidgetCatalog;
use App\Domain\Dashboards\Widgets\WidgetConfiguration;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Widget CRUD on a dashboard (F-06 §4.4): add from the catalog, retitle, configure, show or
 * hide, reorder and delete. Callers authorize through DashboardPolicy `update` (edit level)
 * first; the checks here are business rules. Configuration is validated against the catalog
 * schema as data — never executed — and references must exist inside the organization.
 */
final class DashboardWidgetService
{
    private const AUDIT = ['title', 'configuration', 'layout', 'sort_order', 'is_visible'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Tenancy $tenancy,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function storeRules(): array
    {
        return [
            'widget_catalog_id' => ['required', 'uuid'],
            'title' => ['nullable', 'string', 'min:1', 'max:150'],
            'configuration' => ['sometimes', 'array'],
            'layout' => ['sometimes', 'array'],
            'layout.w' => ['required_with:layout', Rule::in(SystemWidgets::LAYOUT_WIDTHS)],
            'layout.h' => ['required_with:layout', Rule::in(SystemWidgets::LAYOUT_HEIGHTS)],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function updateRules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'min:1', 'max:150'],
            'configuration' => ['sometimes', 'array'],
            'layout' => ['sometimes', 'nullable', 'array'],
            'layout.w' => ['required_with:layout', Rule::in(SystemWidgets::LAYOUT_WIDTHS)],
            'layout.h' => ['required_with:layout', Rule::in(SystemWidgets::LAYOUT_HEIGHTS)],
            'is_visible' => ['sometimes', 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function store(Dashboard $dashboard, array $data, User $user): DashboardWidget
    {
        $catalog = $this->catalog($data['widget_catalog_id']);
        $this->assertPermission($catalog);
        $configuration = $this->configuration($catalog, $data['configuration'] ?? []);
        $layout = $this->layout($data['layout'] ?? null) ?? $catalog->default_layout;

        return DB::transaction(function () use ($dashboard, $catalog, $data, $configuration, $layout) {
            $order = (int) $dashboard->widgets()->max('sort_order') + 1;
            $widget = new DashboardWidget([
                'widget_catalog_id' => $catalog->id,
                'title' => $data['title'] ?? null,
                'configuration' => $configuration,
                'layout' => $layout,
                'sort_order' => $order,
                'is_visible' => true,
            ]);
            $widget->dashboard_id = $dashboard->id;
            $widget->save();
            $this->audit->record('dashboard_widget.created', $widget, $widget->id,
                after: $widget->only(self::AUDIT) + ['widget_key' => $catalog->widget_key],
                metadata: ['dashboard_id' => $dashboard->id]);

            return $widget;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(DashboardWidget $widget, array $data): DashboardWidget
    {
        $widget->loadMissing('catalog');

        return DB::transaction(function () use ($widget, $data) {
            $before = $widget->only(self::AUDIT);
            if (array_key_exists('title', $data)) {
                $widget->title = $data['title'];
            }
            if (array_key_exists('configuration', $data)) {
                $widget->configuration = $this->configuration($widget->catalog, $data['configuration']);
            }
            if (array_key_exists('layout', $data)) {
                $widget->layout = $this->layout($data['layout']) ?? $widget->catalog->default_layout;
            }
            if (array_key_exists('is_visible', $data)) {
                $widget->is_visible = (bool) $data['is_visible'];
            }
            $widget->save();
            $this->audit->record('dashboard_widget.updated', $widget, $widget->id, $before, $widget->only(self::AUDIT));

            return $widget;
        });
    }

    public function delete(DashboardWidget $widget): void
    {
        DB::transaction(function () use ($widget) {
            $snapshot = $widget->only(self::AUDIT) + ['widget_key' => $widget->catalog?->widget_key];
            $widget->delete();
            $this->audit->record('dashboard_widget.deleted', null, $widget->id, after: $snapshot);
        });
    }

    /**
     * Persist a new order: `order` must name every widget of the dashboard exactly once.
     * Widgets keep their configuration and layout; only sort_order changes.
     *
     * @param  list<string>  $order
     */
    public function reorder(Dashboard $dashboard, array $order): void
    {
        $widgets = $dashboard->widgets()->get();
        $ids = $widgets->pluck('id')->sort()->values()->all();
        $given = collect($order)->map('strval')->sort()->values()->all();
        if ($ids !== $given) {
            throw ApiException::unprocessable('INVALID_WIDGET_ORDER', 'Urutan widget harus memuat semua widget dashboard tepat satu kali.');
        }

        DB::transaction(function () use ($widgets, $order) {
            $position = [];
            foreach ($order as $i => $id) {
                $position[$id] = $i;
            }
            foreach ($widgets as $widget) {
                $widget->sort_order = $position[$widget->id];
                $widget->save();
            }
        });
    }

    private function catalog(string $id): WidgetCatalog
    {
        $catalog = WidgetCatalog::query()->whereKey($id)->where('is_active', true)->first()
            ?? throw ApiException::unprocessable('INVALID_WIDGET', 'Widget tidak ditemukan atau tidak aktif.');

        return $catalog;
    }

    /** A member cannot place a widget whose data they may not read. */
    private function assertPermission(WidgetCatalog $catalog): void
    {
        if ($catalog->required_permission !== null && ! $this->tenancy->tenant()->can($catalog->required_permission)) {
            throw ApiException::forbidden('WIDGET_FORBIDDEN', 'Anda tidak memiliki izin untuk widget ini.');
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function configuration(WidgetCatalog $catalog, array $configuration): array
    {
        return WidgetConfiguration::validate($configuration, $catalog->configuration_schema['fields'] ?? []);
    }

    /**
     * @param  array<string, mixed>|null  $layout
     * @return array{w?: int, h?: int}|null
     */
    private function layout(?array $layout): ?array
    {
        if ($layout === null) {
            return null;
        }

        return ['w' => (int) $layout['w'], 'h' => (int) $layout['h']];
    }
}
