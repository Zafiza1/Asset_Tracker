<?php

use App\Http\Controllers\Tenant\ReportController;
use Illuminate\Support\Facades\Route;

// Asset Reports container (inside the tenant route group).
Route::middleware('module:reports')->group(function () {
    Route::get('/reports/assets', [ReportController::class, 'assets'])->middleware('permission:report.view');
    Route::get('/reports/assets/export', [ReportController::class, 'exportAssets'])->middleware('permission:report.export');
});
