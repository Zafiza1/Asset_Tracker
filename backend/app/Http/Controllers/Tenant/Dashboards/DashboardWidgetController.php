<?php

namespace App\Http\Controllers\Tenant\Dashboards;

use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Services\DashboardWidgetService;
use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardWidgetResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Widget CRUD on a dashboard (F-06 §4.4). Every mutation needs the edit level on the
 * dashboard (DashboardPolicy `update`); a dashboard the member may not open answers 404.
 */
class DashboardWidgetController extends Controller
{
    public function __construct(private readonly DashboardWidgetService $service) {}

    public function store(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->editable($dashboard);
        $widget = $this->service->store($dashboard, $request->validate(DashboardWidgetService::storeRules()), $request->user());

        return (new DashboardWidgetResource($widget->load('catalog')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Dashboard $dashboard, string $widget): DashboardWidgetResource
    {
        $this->editable($dashboard);
        $model = $this->widget($dashboard, $widget);
        $updated = $this->service->update($model, $request->validate(DashboardWidgetService::updateRules()));

        return new DashboardWidgetResource($updated->load('catalog'));
    }

    public function destroy(Dashboard $dashboard, string $widget): Response
    {
        $this->editable($dashboard);
        $this->service->delete($this->widget($dashboard, $widget));

        return response()->noContent();
    }

    /** Persist a new widget order (drag & drop in the layout editor). */
    public function reorder(Request $request, Dashboard $dashboard): Response
    {
        $this->editable($dashboard);
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['uuid', 'distinct']]);
        $this->service->reorder($dashboard, $data['order']);

        return response()->noContent();
    }

    private function editable(Dashboard $dashboard): void
    {
        if (Gate::denies('view', $dashboard)) {
            throw (new ModelNotFoundException)->setModel(Dashboard::class);
        }
        Gate::authorize('update', $dashboard);
    }

    private function widget(Dashboard $dashboard, string $id): DashboardWidget
    {
        return DashboardWidget::query()->where('dashboard_id', $dashboard->id)->find($id)
            ?? throw (new ModelNotFoundException)->setModel(DashboardWidget::class);
    }
}
