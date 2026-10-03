<?php

use App\Modules\Delivery\Http\DeliveryController;
use Illuminate\Support\Facades\Route;

/*
| Loaded by DeliveryServiceProvider under /api/v1/deliveries with the
| auth:sanctum, tenant and module:delivery middleware: the endpoints only
| exist for projects that have the module enabled.
*/

Route::get('/', [DeliveryController::class, 'index'])->middleware('throttle:api');
Route::post('/', [DeliveryController::class, 'store'])->middleware('throttle:api-write');

Route::whereNumber('delivery')->group(function () {
    Route::get('{delivery}', [DeliveryController::class, 'show'])->middleware('throttle:api');
    Route::put('{delivery}', [DeliveryController::class, 'update'])->middleware('throttle:api-write');
    Route::post('{delivery}/dispatch', [DeliveryController::class, 'dispatchDelivery'])->middleware('throttle:api-write');
    Route::post('{delivery}/deliver', [DeliveryController::class, 'deliver'])->middleware('throttle:api-write');
    Route::post('{delivery}/return', [DeliveryController::class, 'returnAssets'])->middleware('throttle:api-write');
    Route::post('{delivery}/cancel', [DeliveryController::class, 'cancel'])->middleware('throttle:api-write');
});
