<?php

use App\Http\Controllers\Tenant\ReportController;
use App\Http\Controllers\Tenant\ReportFieldController;
use App\Http\Controllers\Tenant\ReportTemplateController;
use Illuminate\Support\Facades\Route;

// Asset Reports container (inside the tenant route group): the fixed asset register report
// plus the Custom Report Designer (templates, field registry; generation lands in later phases).
Route::middleware('module:reports')->group(function () {
    Route::get('/reports/assets', [ReportController::class, 'assets'])->middleware('permission:report.view');
    Route::get('/reports/assets/export', [ReportController::class, 'exportAssets'])->middleware('permission:report.export');

    // Field registry: the only bindings a template may use (read by the designer and validators).
    Route::get('/report-fields', [ReportFieldController::class, 'index'])->middleware('permission:report.view');
    Route::get('/report-fields/{source}', [ReportFieldController::class, 'show'])->middleware('permission:report.view');

    // Report templates: viewing needs report.view; managing needs the report_designer group.
    Route::get('/report-templates', [ReportTemplateController::class, 'index'])->middleware('permission:report.view');
    Route::post('/report-templates', [ReportTemplateController::class, 'store'])->middleware('permission:report.create');
    Route::get('/report-templates/{reportTemplate}', [ReportTemplateController::class, 'show'])->middleware('permission:report.view');
    Route::put('/report-templates/{reportTemplate}', [ReportTemplateController::class, 'update'])->middleware('permission:report.update');
    Route::delete('/report-templates/{reportTemplate}', [ReportTemplateController::class, 'destroy'])->middleware('permission:report.delete');
    Route::post('/report-templates/{reportTemplate}/duplicate', [ReportTemplateController::class, 'duplicate'])->middleware('permission:report.create');
    Route::post('/report-templates/{reportTemplate}/publish', [ReportTemplateController::class, 'publish'])->middleware('permission:report.publish');
    Route::post('/report-templates/{reportTemplate}/archive', [ReportTemplateController::class, 'archive'])->middleware('permission:report.publish');
    // Data binding: registry validation while designing, live-data preview for authorized users.
    Route::post('/report-templates/{reportTemplate}/validate', [ReportTemplateController::class, 'validateDesign'])->middleware('permission:report.update');
    Route::post('/report-templates/{reportTemplate}/preview', [ReportTemplateController::class, 'preview'])->middleware('permission:report.preview');
    Route::get('/report-templates/{reportTemplate}/versions', [ReportTemplateController::class, 'versions'])->middleware('permission:report.view');
});
