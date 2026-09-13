<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use CjDropshipping\Controllers\CjDropshippingController;

Route::prefix('api/admin/cj')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/search', [CjDropshippingController::class, 'search']);
    Route::get('/product/{pid}', [CjDropshippingController::class, 'detail']);
    Route::post('/import', [CjDropshippingController::class, 'import']);
    Route::post('/bulk-import', [CjDropshippingController::class, 'bulkImport']);
    Route::get('/bulk-import-batches', [CjDropshippingController::class, 'getBulkImportBatches']);
    Route::get('/bulk-import-status/{batchId}', [CjDropshippingController::class, 'getBulkImportStatus']);
    Route::post('/process-batch/{batchId}', [CjDropshippingController::class, 'processBatch']);
    Route::post('/shipping-calculate', [CjDropshippingController::class, 'calculateShipping']);
    Route::post('/orders/{orderId}/fulfill', [CjDropshippingController::class, 'fulfillOrder']);
});
