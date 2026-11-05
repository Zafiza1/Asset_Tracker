<?php

use App\Http\Controllers\Tenant\AssetController;
use Illuminate\Support\Facades\Route;

// Asset Management container (inside the tenant route group). Writes by users without
// approval.manage wait for approval (`approval:<action>`, see ApprovalActions).
Route::middleware('module:assets')->group(function () {
    Route::get('/assets', [AssetController::class, 'index'])->middleware('permission:asset.view');
    Route::post('/assets', [AssetController::class, 'store'])->middleware(['permission:asset.create', 'approval:asset.create']);
    Route::post('/assets/import', [AssetController::class, 'import'])->middleware(['permission:asset.create', 'approval:asset.import']);
    Route::post('/assets/bulk-update', [AssetController::class, 'bulkUpdate'])->middleware(['permission:asset.update', 'approval:asset.bulk_update']);
    Route::get('/assets/template', [AssetController::class, 'downloadTemplate'])->middleware('permission:asset.create');
    // Bulk edit in Excel: the filtered assets as .xlsx, then the edited file back.
    Route::get('/assets/edit-sheet', [AssetController::class, 'downloadEditSheet'])->middleware('permission:asset.update');
    Route::post('/assets/edit-sheet', [AssetController::class, 'importEdits'])->middleware(['permission:asset.update', 'approval:asset.import_edits']);
    // Scanned 1D/2D barcode content (the asset number) -> asset detail.
    Route::get('/assets/lookup', [AssetController::class, 'lookup'])->middleware('permission:asset.view');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->middleware('permission:asset.view');
    Route::patch('/assets/{asset}', [AssetController::class, 'update'])->middleware(['permission:asset.update', 'approval:asset.update']);
    Route::get('/assets/{asset}/image', [AssetController::class, 'image'])->middleware('permission:asset.view');
    Route::post('/assets/{asset}/image', [AssetController::class, 'uploadImage'])->middleware(['permission:asset.update', 'approval:asset.image']);
    Route::delete('/assets/{asset}/image', [AssetController::class, 'deleteImage'])->middleware(['permission:asset.update', 'approval:asset.image']);
    Route::get('/assets/{asset}/document', [AssetController::class, 'document'])->middleware('permission:asset.view');
    Route::post('/assets/{asset}/document', [AssetController::class, 'uploadDocument'])->middleware(['permission:asset.update', 'approval:asset.document']);
    Route::delete('/assets/{asset}/document', [AssetController::class, 'deleteDocument'])->middleware(['permission:asset.update', 'approval:asset.document']);
    Route::post('/assets/{asset}/archive', [AssetController::class, 'archive'])->middleware(['permission:asset.archive', 'approval:asset.archive']);
    Route::post('/assets/{asset}/restore', [AssetController::class, 'restore'])->middleware(['permission:asset.restore', 'approval:asset.restore']);
    Route::get('/assets/{asset}/transactions', [AssetController::class, 'transactions'])->middleware('permission:asset.view,transaction.view');
});
