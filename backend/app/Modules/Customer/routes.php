<?php

use App\Modules\Customer\Http\CustomerController;
use Illuminate\Support\Facades\Route;

/*
| Loaded by CustomerServiceProvider under /api/v1/customers with the
| auth:sanctum, tenant and module:customer middleware: the endpoints only
| exist for projects that have the module enabled.
*/

Route::get('/', [CustomerController::class, 'index'])->middleware('throttle:api');
Route::post('/', [CustomerController::class, 'store'])->middleware('throttle:api-write');
Route::get('{customer}', [CustomerController::class, 'show'])->middleware('throttle:api')->whereNumber('customer');
Route::put('{customer}', [CustomerController::class, 'update'])->middleware('throttle:api-write')->whereNumber('customer');
Route::delete('{customer}', [CustomerController::class, 'destroy'])->middleware('throttle:api-write')->whereNumber('customer');
