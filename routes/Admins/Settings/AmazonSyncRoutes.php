<?php

use App\Http\Controllers\Admin\AmazonSyncSettingsController;
use Illuminate\Support\Facades\Route;

// Admin Amazon Smart Sync Settings Routes (Dedicated & Isolated)
Route::prefix('admin/settings/amazon-sync')->group(function () {
    Route::get('/status', [AmazonSyncSettingsController::class, 'status']);
    Route::post('/settings', [AmazonSyncSettingsController::class, 'updateSettings']);
    Route::post('/trigger', [AmazonSyncSettingsController::class, 'triggerSync']);
    Route::post('/test-provider', [AmazonSyncSettingsController::class, 'testProvider']);
    Route::post('/reset-usage', [AmazonSyncSettingsController::class, 'resetUsage']);
    Route::post('/unpause-asins', [AmazonSyncSettingsController::class, 'unpauseAsins']);
    Route::get('/products', [AmazonSyncSettingsController::class, 'listProducts']);
    Route::get('/product-ids', [AmazonSyncSettingsController::class, 'productIds']);
    Route::post('/sync-products', [AmazonSyncSettingsController::class, 'syncProducts']);
    Route::get('/logs', [AmazonSyncSettingsController::class, 'syncLogs']);
});

// Storefront Just-in-Time (JIT) On-Demand Amazon Sync Endpoint
Route::post('/amazon-sync/jit-check', [AmazonSyncSettingsController::class, 'jitCheck']);
