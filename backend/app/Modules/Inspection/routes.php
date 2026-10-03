<?php

use App\Modules\Inspection\Http\InspectionController;
use Illuminate\Support\Facades\Route;

/*
| Loaded by InspectionServiceProvider under /api/v1/inspections with the
| auth:sanctum, tenant and module:inspection middleware: the endpoints only
| exist for projects that have the module enabled.
*/

Route::get('/', [InspectionController::class, 'index'])->middleware('throttle:api');
Route::post('/', [InspectionController::class, 'store'])->middleware('throttle:api-write');

Route::get('checklists', [InspectionController::class, 'checklists'])->middleware('throttle:api');
Route::post('checklists', [InspectionController::class, 'storeChecklist'])->middleware('throttle:api-write');
Route::put('checklists/{checklist}', [InspectionController::class, 'updateChecklist'])->middleware('throttle:api-write')->whereNumber('checklist');

Route::whereNumber('inspection')->group(function () {
    Route::get('{inspection}', [InspectionController::class, 'show'])->middleware('throttle:api');
    Route::put('{inspection}', [InspectionController::class, 'update'])->middleware('throttle:api-write');
    Route::post('{inspection}/record', [InspectionController::class, 'record'])->middleware('throttle:api-write');
});
