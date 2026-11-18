<?php

use App\Http\Controllers\Tenant\AssetTransactionController;
use App\Http\Controllers\Tenant\TransactionFlowController;
use App\Http\Controllers\Tenant\TransactionRunController;
use App\Http\Controllers\Tenant\TransactionTypeController;
use Illuminate\Support\Facades\Route;

// Asset Transactions container (inside the tenant route group). Type-specific permissions
// (asset.transfer, asset.assign, asset.status_change, asset.dispose) are checked by the service.
// Built-in transactions apply at once. An organization's own type with a published flow starts a
// run instead (F-07b): its approval steps are flow tasks, not ApprovalRequest replays (D-30).
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

    // The flow of a transaction type (F-07b): read by the transaction form, drawn and published
    // by Super Admin and Supervisor (transaction_flow.manage, D-27).
    Route::get('/transaction-types/{transactionType}/flow', [TransactionFlowController::class, 'show'])->middleware('permission:transaction.view');
    Route::put('/transaction-types/{transactionType}/flow', [TransactionFlowController::class, 'update'])->middleware('permission:transaction_flow.manage');
    Route::post('/transaction-types/{transactionType}/flow/validate', [TransactionFlowController::class, 'validateFlow'])->middleware('permission:transaction_flow.manage');
    Route::post('/transaction-types/{transactionType}/flow/publish', [TransactionFlowController::class, 'publish'])->middleware('permission:transaction_flow.manage');
    Route::post('/transaction-types/{transactionType}/flow/simulate', [TransactionFlowController::class, 'simulate'])->middleware('permission:transaction_flow.manage');

    // Transactions going through a flow (F-07b). Who may work a step is checked by the engine:
    // the task's assignee, the run's creator, or transaction.reverse for cancelling.
    Route::get('/transaction-runs', [TransactionRunController::class, 'index'])->middleware('permission:transaction.view');
    Route::get('/transaction-runs/{transactionRun}', [TransactionRunController::class, 'show'])->middleware('permission:transaction.view');
    Route::post('/transaction-runs/{transactionRun}/tasks/{task}/complete', [TransactionRunController::class, 'complete'])->middleware('permission:transaction.view');
    Route::post('/transaction-runs/{transactionRun}/cancel', [TransactionRunController::class, 'cancel'])->middleware('permission:transaction.view');
});
