<?php

namespace App\Http\Controllers\Tenant\Dashboards;

use App\Domain\Dashboards\Models\Dashboard;
use App\Domain\Dashboards\Models\DashboardWidget;
use App\Domain\Dashboards\Widgets\WidgetDataService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One widget's data (F-06 §4.4): fetched per widget so a slow or failing widget never blocks
 * the dashboard. Opening the dashboard grants nothing by itself — the service checks the
 * widget's `required_permission` and every provider runs inside the member's data scope.
 */
class WidgetDataController extends Controller
{
    public function __construct(private readonly WidgetDataService $service) {}

    public function show(Request $request, Dashboard $dashboard, string $widget): JsonResponse
    {
        if (Gate::denies('view', $dashboard)) {
            throw (new ModelNotFoundException)->setModel(Dashboard::class);
        }
        $model = DashboardWidget::query()->where('dashboard_id', $dashboard->id)->find($widget)
            ?? throw (new ModelNotFoundException)->setModel(DashboardWidget::class);

        return response()->json(['data' => $this->service->payload($model)]);
    }
}
