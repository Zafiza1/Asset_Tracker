<?php

use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\Dashboards\DashboardController as DashboardBuilderController;
use App\Http\Controllers\Tenant\Dashboards\DashboardPreferenceController;
use App\Http\Controllers\Tenant\Dashboards\DashboardTemplateController;
use App\Http\Controllers\Tenant\Dashboards\WidgetCatalogController;
use Illuminate\Support\Facades\Route;

// Asset Analytics container (inside the tenant route group).
Route::middleware('module:analytics')->group(function () {
    // Fixed dashboard figures of the current UI (kept until the Dashboard Builder UI replaces it).
    Route::get('/dashboard', [DashboardController::class, 'show'])->middleware('permission:report.view');

    // Dashboard Builder. dashboard.view is the coarse check; DashboardPolicy decides per
    // dashboard (owner, shares, dashboard.manage). Widget data checks the data permissions.
    $view = 'permission:dashboard.view';
    Route::get('/dashboards', [DashboardBuilderController::class, 'index'])->middleware($view);
    Route::post('/dashboards', [DashboardBuilderController::class, 'store'])->middleware($view);
    Route::get('/dashboards/navigation', [DashboardBuilderController::class, 'navigation'])->middleware($view);
    Route::get('/dashboards/default', [DashboardBuilderController::class, 'default'])->middleware($view);
    Route::get('/dashboards/overview', [DashboardBuilderController::class, 'overview'])->middleware($view);
    Route::get('/dashboards/categories/{tenantCategory}', [DashboardBuilderController::class, 'category'])->middleware($view)->whereUuid('tenantCategory');
    Route::get('/dashboards/{dashboard}', [DashboardBuilderController::class, 'show'])->middleware($view);
    Route::patch('/dashboards/{dashboard}', [DashboardBuilderController::class, 'update'])->middleware($view);
    Route::delete('/dashboards/{dashboard}', [DashboardBuilderController::class, 'destroy'])->middleware($view);
    Route::post('/dashboards/{dashboard}/apply-template', [DashboardBuilderController::class, 'applyTemplate'])->middleware($view);

    Route::get('/dashboard-preferences', [DashboardPreferenceController::class, 'show'])->middleware($view);
    Route::put('/dashboard-preferences', [DashboardPreferenceController::class, 'update'])->middleware($view);

    Route::get('/widget-catalog', [WidgetCatalogController::class, 'index'])->middleware($view);

    // Templates: system ones are read-only; organization ones are managed with dashboard.manage.
    $manage = 'permission:dashboard.manage';
    Route::get('/dashboard-templates', [DashboardTemplateController::class, 'index'])->middleware($view);
    Route::post('/dashboard-templates', [DashboardTemplateController::class, 'store'])->middleware([$view, $manage]);
    Route::get('/dashboard-templates/{dashboardTemplate}', [DashboardTemplateController::class, 'show'])->middleware($view);
    Route::patch('/dashboard-templates/{dashboardTemplate}', [DashboardTemplateController::class, 'update'])->middleware([$view, $manage]);
    Route::delete('/dashboard-templates/{dashboardTemplate}', [DashboardTemplateController::class, 'destroy'])->middleware([$view, $manage]);
});
