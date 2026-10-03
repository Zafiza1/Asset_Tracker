<?php

use App\Modules\Rental\Http\RentalController;
use Illuminate\Support\Facades\Route;

/*
| Loaded by RentalServiceProvider under /api/v1/rentals with the
| auth:sanctum, tenant and module:rental middleware: the endpoints only
| exist for projects that have the module enabled.
*/

Route::get('/', [RentalController::class, 'index'])->middleware('throttle:api');
Route::post('/', [RentalController::class, 'store'])->middleware('throttle:api-write');

Route::whereNumber('rental')->group(function () {
    Route::get('{rental}', [RentalController::class, 'show'])->middleware('throttle:api');
    Route::post('{rental}/checkout', [RentalController::class, 'checkout'])->middleware('throttle:api-write');
    Route::post('{rental}/extend', [RentalController::class, 'extend'])->middleware('throttle:api-write');
    Route::post('{rental}/return', [RentalController::class, 'returnAsset'])->middleware('throttle:api-write');
    Route::post('{rental}/cancel', [RentalController::class, 'cancel'])->middleware('throttle:api-write');
});
