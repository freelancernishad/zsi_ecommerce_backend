<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use Amazon\Controllers\AmazonController;

Route::prefix('api/admin/amazon')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/search', [AmazonController::class, 'search']);
    Route::get('/parse', [AmazonController::class, 'parse']);
    Route::get('/product/{asin}', [AmazonController::class, 'detail']);
    Route::post('/import', [AmazonController::class, 'import']);
    Route::post('/bulk-import', [AmazonController::class, 'bulkImport']);
    Route::get('/bulk-import-batches', [AmazonController::class, 'getBulkImportBatches']);
    Route::get('/bulk-import-status/{batchId}', [AmazonController::class, 'getBulkImportStatus']);
    Route::post('/process-batch/{batchId}', [AmazonController::class, 'processBatch']);
});
