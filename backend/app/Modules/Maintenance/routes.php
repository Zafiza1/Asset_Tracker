<?php

use App\Modules\Maintenance\Http\MaintenanceController;
use Illuminate\Support\Facades\Route;

/*
| Loaded by MaintenanceServiceProvider under /api/v1/maintenance with the
| auth:sanctum, tenant and module:maintenance middleware: the endpoints only
| exist for projects that have the module enabled.
*/

Route::get('/', [MaintenanceController::class, 'index'])->middleware('throttle:api');
Route::post('/', [MaintenanceController::class, 'store'])->middleware('throttle:api-write');
Route::get('{maintenance}', [MaintenanceController::class, 'show'])->middleware('throttle:api')->whereNumber('maintenance');
Route::put('{maintenance}', [MaintenanceController::class, 'update'])->middleware('throttle:api-write')->whereNumber('maintenance');
Route::post('{maintenance}/complete', [MaintenanceController::class, 'complete'])->middleware('throttle:api-write')->whereNumber('maintenance');
