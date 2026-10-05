<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use AliExpress\Controllers\AliExpressController;

Route::prefix('api/admin/aliexpress')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/search', [AliExpressController::class, 'search']);
    Route::get('/parse', [AliExpressController::class, 'parse']);
    Route::get('/product/{productId}', [AliExpressController::class, 'detail']);
    Route::post('/import', [AliExpressController::class, 'import']);
    Route::post('/bulk-import', [AliExpressController::class, 'bulkImport']);
    Route::get('/bulk-import-batches', [AliExpressController::class, 'getBulkImportBatches']);
    Route::get('/bulk-import-status/{batchId}', [AliExpressController::class, 'getBulkImportStatus']);
    Route::post('/process-batch/{batchId}', [AliExpressController::class, 'processBatch']);
});
