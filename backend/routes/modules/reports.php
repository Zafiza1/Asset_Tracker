<?php

use App\Http\Controllers\Tenant\ReportExportController;
use App\Http\Controllers\Tenant\ReportFieldController;
use App\Http\Controllers\Tenant\ReportTemplateController;
use Illuminate\Support\Facades\Route;

// Reports container (inside the tenant route group): every report is built by the organization
// in the Report Designer (templates, field registry, running templates into PDF/Excel).
// The fixed asset register report was removed at the product owner's request (2026-11-18).
Route::middleware('module:reports')->group(function () {
    // Field registry: the only bindings a template may use (read by the designer and validators).
    Route::get('/report-fields', [ReportFieldController::class, 'index'])->middleware('permission:report.view');
    Route::get('/report-fields/{source}', [ReportFieldController::class, 'show'])->middleware('permission:report.view');

    // Report templates: viewing needs report.view; managing needs the report_designer group.
    Route::get('/report-templates', [ReportTemplateController::class, 'index'])->middleware('permission:report.view');
    Route::post('/report-templates', [ReportTemplateController::class, 'store'])->middleware('permission:report.create');
    // Ready-made samples (F-04): list them, copy one into the organization as a draft.
    Route::get('/report-templates/samples', [ReportTemplateController::class, 'samples'])->middleware('permission:report.view');
    Route::post('/report-templates/from-sample', [ReportTemplateController::class, 'fromSample'])->middleware('permission:report.create');
    Route::get('/report-templates/{reportTemplate}', [ReportTemplateController::class, 'show'])->middleware('permission:report.view');
    Route::put('/report-templates/{reportTemplate}', [ReportTemplateController::class, 'update'])->middleware('permission:report.update');
    Route::delete('/report-templates/{reportTemplate}', [ReportTemplateController::class, 'destroy'])->middleware('permission:report.delete');
    Route::post('/report-templates/{reportTemplate}/duplicate', [ReportTemplateController::class, 'duplicate'])->middleware('permission:report.create');
    Route::post('/report-templates/{reportTemplate}/publish', [ReportTemplateController::class, 'publish'])->middleware('permission:report.publish');
    Route::post('/report-templates/{reportTemplate}/archive', [ReportTemplateController::class, 'archive'])->middleware('permission:report.publish');
    // Data binding: registry validation while designing, live-data preview for authorized users.
    Route::post('/report-templates/{reportTemplate}/validate', [ReportTemplateController::class, 'validateDesign'])->middleware('permission:report.update');
    // Running a published template (F-05): filters, live preview, PDF/Excel, history.
    Route::get('/report-templates/{reportTemplate}/filters', [ReportExportController::class, 'filters'])->middleware('permission:report.generate');
    Route::post('/report-templates/{reportTemplate}/run-preview', [ReportExportController::class, 'runPreview'])->middleware('permission:report.generate');
    Route::post('/report-templates/{reportTemplate}/exports', [ReportExportController::class, 'store'])->middleware(['permission:report.generate', 'throttle:report-exports']);
    Route::get('/report-exports', [ReportExportController::class, 'index'])->middleware('permission:report.view');
    Route::get('/report-exports/{reportExport}/download', [ReportExportController::class, 'download'])->middleware('permission:report.download');
    Route::post('/report-templates/{reportTemplate}/preview', [ReportTemplateController::class, 'preview'])->middleware('permission:report.preview');
    Route::get('/report-templates/{reportTemplate}/versions', [ReportTemplateController::class, 'versions'])->middleware('permission:report.view');
});
