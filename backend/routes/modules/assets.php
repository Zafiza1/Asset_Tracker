<?php

use App\Http\Controllers\Tenant\AssetController;
use Illuminate\Support\Facades\Route;

// Asset Management container (inside the tenant route group).
Route::middleware('module:assets')->group(function () {
    Route::get('/assets', [AssetController::class, 'index'])->middleware('permission:asset.view');
    Route::post('/assets', [AssetController::class, 'store'])->middleware('permission:asset.create');
    Route::post('/assets/import', [AssetController::class, 'import'])->middleware('permission:asset.create');
    Route::get('/assets/template', [AssetController::class, 'downloadTemplate'])->middleware('permission:asset.create');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->middleware('permission:asset.view');
    Route::patch('/assets/{asset}', [AssetController::class, 'update'])->middleware('permission:asset.update');
    Route::post('/assets/{asset}/archive', [AssetController::class, 'archive'])->middleware('permission:asset.archive');
    Route::post('/assets/{asset}/restore', [AssetController::class, 'restore'])->middleware('permission:asset.restore');
    Route::get('/assets/{asset}/transactions', [AssetController::class, 'transactions'])->middleware('permission:asset.view,transaction.view');
});
