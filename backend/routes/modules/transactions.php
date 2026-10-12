<?php

use App\Http\Controllers\Tenant\AssetTransactionController;
use Illuminate\Support\Facades\Route;

// Asset Transactions container (inside the tenant route group). Type-specific permissions
// (asset.transfer, asset.assign, asset.status_change, asset.dispose) are checked by the service.
Route::middleware('module:transactions')->group(function () {
    Route::get('/transactions', [AssetTransactionController::class, 'index'])->middleware('permission:transaction.view');
    Route::post('/transactions', [AssetTransactionController::class, 'store'])->middleware('permission:transaction.create');
    Route::get('/transactions/{assetTransaction}', [AssetTransactionController::class, 'show'])->middleware('permission:transaction.view');
});
