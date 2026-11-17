<?php

namespace App\Domain\Dashboards\Services;

use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardTemplate;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Models\TemplateWidget;
use App\Domain\Dashboards\Models\WidgetCatalog;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use Illuminate\Support\Collection;

/**
 * Copies widgets between templates and dashboards. Copies are independent: a dashboard keeps
 * its widgets when its template changes, and a template keeps its widgets when the dashboard
 * it was captured from changes. Widgets of deactivated catalog entries are not copied.
 *
 * Attribute keys differ per organization, so templates name date attributes by label
 * (`attribute_label`): on a category dashboard the label becomes that category's attribute key
 * (its own or inherited fields); capturing a dashboard into a template turns keys back into labels.
 */
final class DashboardBuilderService
{
    public function __construct(private readonly CategoryAttributeService $attributes) {}

    /** Replace the dashboard's widgets with copies of the template's and record the version copied. */
    public function applyTemplate(Dashboard $dashboard, DashboardTemplate $template): void
    {
        $dashboard->widgets()->delete();
        $categoryAttributes = $dashboard->category_id !== null ? $this->attributes->effective($dashboard->category_id) : collect();

        foreach ($this->activeWidgets($template) as $order => $widget) {
            $copy = new DashboardWidget([
                'widget_catalog_id' => $widget->widget_catalog_id,
                'title' => $widget->title,
                'configuration' => $this->resolveLabels($widget->configuration, $widget->catalog, $categoryAttributes),
                'layout' => $widget->layout ?: $widget->catalog->default_layout,
                'sort_order' => $order,
                'is_visible' => true,
            ]);
            $copy->dashboard_id = $dashboard->id;
            $copy->save();
        }

        $dashboard->template_id = $template->id;
        $dashboard->template_version = $template->version;
        $dashboard->save();
    }

    /** Replace an organization template's widgets with copies of another template's. */
    public function copyTemplateWidgets(DashboardTemplate $from, DashboardTemplate $to): void
    {
        $to->widgets()->delete();
        foreach ($this->activeWidgets($from) as $order => $widget) {
            $this->templateWidget($to, $widget->widget_catalog_id, $widget->title, $widget->configuration, $widget->layout, $order);
        }
    }

    /** Replace an organization template's widgets with the visible widgets of a dashboard. */
    public function captureDashboard(Dashboard $dashboard, DashboardTemplate $to): void
    {
        $to->widgets()->delete();
        $widgets = $dashboard->widgets()->with('catalog')->where('is_visible', true)->get()
            ->filter(fn (DashboardWidget $w) => $w->catalog->is_active)->values();
        $keys = $widgets->map(fn (DashboardWidget $w) => $w->configuration['attribute'] ?? null)->filter()->unique()->values();
        $labels = $keys->isEmpty() ? collect() : TenantCategoryAttribute::query()->whereIn('key', $keys)->pluck('label', 'key');

        foreach ($widgets as $order => $widget) {
            $configuration = $widget->configuration;
            if (isset($configuration['attribute'], $labels[$configuration['attribute']])) {
                $configuration['attribute_label'] = $labels[$configuration['attribute']];
                unset($configuration['attribute']);
            }
            $this->templateWidget($to, $widget->widget_catalog_id, $widget->title, $configuration, $widget->layout, $order);
        }
    }

    /** @return Collection<int, TemplateWidget> */
    private function activeWidgets(DashboardTemplate $template): Collection
    {
        return $template->widgets()->with('catalog')->get()
            ->filter(fn (TemplateWidget $w) => $w->catalog->is_active)->values();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  Collection<int, TenantCategoryAttribute>  $categoryAttributes
     * @return array<string, mixed>
     */
    private function resolveLabels(array $configuration, WidgetCatalog $catalog, Collection $categoryAttributes): array
    {
        $label = $configuration['attribute_label'] ?? null;
        if (! is_string($label) || $categoryAttributes->isEmpty()) {
            return $configuration;
        }
        $dataType = $catalog->configuration_schema['fields']['attribute']['data_type'] ?? null;
        $match = $categoryAttributes->first(fn (TenantCategoryAttribute $a) => $a->status === MasterStatus::ACTIVE
            && mb_strtolower($a->label) === mb_strtolower($label)
            && ($dataType === null || $a->data_type === $dataType));
        if ($match === null) {
            return $configuration;
        }
        unset($configuration['attribute_label']);

        return ['attribute' => $match->key] + $configuration;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $layout
     */
    private function templateWidget(DashboardTemplate $template, string $catalogId, ?string $title, array $configuration, array $layout, int $order): void
    {
        $widget = new TemplateWidget([
            'widget_catalog_id' => $catalogId,
            'title' => $title,
            'configuration' => $configuration,
            'layout' => $layout,
            'sort_order' => $order,
        ]);
        $widget->template_id = $template->id;
        $widget->save();
    }
}
