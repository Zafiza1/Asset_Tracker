<?php

use App\Http\Controllers\Tenant\AssetTransactionController;
use App\Http\Controllers\Tenant\TransactionTypeController;
use Illuminate\Support\Facades\Route;

// Asset Transactions container (inside the tenant route group). Type-specific permissions
// (asset.transfer, asset.assign, asset.status_change, asset.dispose) are checked by the service.
// Transactions apply at once, without approval (approval covers assets and master data only);
// flows with approval steps for the organization's own types come with F-07b.
Route::middleware('module:transactions')->group(function () {
    Route::get('/transactions', [AssetTransactionController::class, 'index'])->middleware('permission:transaction.view');
    Route::get('/transactions/counts', [AssetTransactionController::class, 'counts'])->middleware('permission:transaction.view');
    Route::post('/transactions', [AssetTransactionController::class, 'store'])->middleware('permission:transaction.create');
    Route::get('/transactions/{assetTransaction}', [AssetTransactionController::class, 'show'])->middleware('permission:transaction.view');

    // The organization's own transaction types and their fields (F-07): read by the transaction
    // form, changed only by Super Admin and Supervisor (transaction_type.manage, D-27).
    Route::get('/transaction-types', [TransactionTypeController::class, 'index'])->middleware('permission:transaction.view');
    Route::post('/transaction-types', [TransactionTypeController::class, 'store'])->middleware('permission:transaction_type.manage');
    Route::get('/transaction-types/{transactionType}', [TransactionTypeController::class, 'show'])->middleware('permission:transaction.view');
    Route::put('/transaction-types/{transactionType}', [TransactionTypeController::class, 'update'])->middleware('permission:transaction_type.manage');
    Route::delete('/transaction-types/{transactionType}', [TransactionTypeController::class, 'destroy'])->middleware('permission:transaction_type.manage');
});
