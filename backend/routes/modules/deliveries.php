<?php

use App\Http\Controllers\Tenant\DeliveryController;
use Illuminate\Support\Facades\Route;

// Deliveries container (inside the tenant route group): incoming and outgoing transactions of
// cylinders. Planned on the web (delivery.manage); crew, scans and reports come from the phone
// (delivery.operate). Planning also needs asset.outgoing or asset.incoming (DeliveryService).
Route::middleware('module:transactions')->group(function () {
    Route::get('/deliveries', [DeliveryController::class, 'index'])->middleware('permission:delivery.view');
    Route::get('/deliveries/tracking', [DeliveryController::class, 'tracking'])->middleware('permission:delivery.view');
    Route::get('/deliveries/options', [DeliveryController::class, 'options'])->middleware('permission:delivery.view');
    Route::get('/deliveries/cylinders', [DeliveryController::class, 'cylinders'])->middleware('permission:delivery.view');
    Route::post('/deliveries', [DeliveryController::class, 'store'])->middleware('permission:delivery.manage');
    Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])->middleware('permission:delivery.view');
    Route::put('/deliveries/{delivery}', [DeliveryController::class, 'update'])->middleware('permission:delivery.manage');
    Route::post('/deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel'])->middleware('permission:delivery.manage');
    Route::put('/deliveries/{delivery}/crew', [DeliveryController::class, 'crew'])->middleware('permission:delivery.operate');
    Route::post('/deliveries/{delivery}/scan', [DeliveryController::class, 'scan'])->middleware('permission:delivery.operate');
    Route::post('/deliveries/{delivery}/unscan', [DeliveryController::class, 'unscan'])->middleware('permission:delivery.operate');
    Route::post('/deliveries/{delivery}/events', [DeliveryController::class, 'report'])->middleware('permission:delivery.operate');
});
