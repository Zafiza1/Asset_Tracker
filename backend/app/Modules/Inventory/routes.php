<?php

use App\Modules\Inventory\Http\InventoryController;
use Illuminate\Support\Facades\Route;

/*
| Loaded by InventoryServiceProvider under /api/v1/inventory with the
| auth:sanctum, tenant and module:inventory middleware: the endpoints only
| exist for projects that have the module enabled.
*/

Route::get('stock', [InventoryController::class, 'stock'])->middleware('throttle:api');

Route::get('levels', [InventoryController::class, 'levels'])->middleware('throttle:api');
Route::put('levels', [InventoryController::class, 'setLevel'])->middleware('throttle:api-write');
Route::delete('levels/{level}', [InventoryController::class, 'deleteLevel'])->middleware('throttle:api-write')->whereNumber('level');

Route::get('counts', [InventoryController::class, 'counts'])->middleware('throttle:api');
Route::post('counts', [InventoryController::class, 'openCount'])->middleware('throttle:api-write');

Route::whereNumber('count')->group(function () {
    Route::get('counts/{count}', [InventoryController::class, 'showCount'])->middleware('throttle:api');
    // Scanners send bursts of reads: the integration limit applies.
    Route::post('counts/{count}/scan', [InventoryController::class, 'scan'])->middleware('throttle:integration');
    Route::post('counts/{count}/complete', [InventoryController::class, 'complete'])->middleware('throttle:api-write');
    Route::post('counts/{count}/cancel', [InventoryController::class, 'cancelCount'])->middleware('throttle:api-write');
});
