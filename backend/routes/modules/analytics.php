<?php

use App\Http\Controllers\Tenant\DashboardController;
use Illuminate\Support\Facades\Route;

// Asset Analytics container (inside the tenant route group).
Route::middleware('module:analytics')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'show'])->middleware('permission:report.view');
});
