<?php
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Controllers\Admin\Products\ProductController;
use App\Http\Controllers\Admin\Products\ProductImportController;

Route::prefix('admin/products')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    Route::post('/import-shopify', [ProductImportController::class, 'importShopify']);
    Route::post('/fix-inverted-prices', [ProductImportController::class, 'fixInvertedPrices']);

    // Inventory Management
    Route::get('/inventory', [\App\Http\Controllers\Admin\Products\InventoryController::class, 'index']);
    Route::put('/inventory/{id}/quantity', [\App\Http\Controllers\Admin\Products\InventoryController::class, 'updateQuantity']);
    Route::post('/inventory/bulk-update', [\App\Http\Controllers\Admin\Products\InventoryController::class, 'bulkUpdateQuantity']);
    Route::get('/inventory/export', [\App\Http\Controllers\Admin\Products\InventoryController::class, 'exportCsv']);
    Route::post('/inventory/import-csv', [\App\Http\Controllers\Admin\Products\InventoryController::class, 'importCsv']);

    // Individual actions
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);

    // Bulk actions
    Route::post('/bulk-status', [ProductController::class, 'bulkUpdateStatus']);
    Route::post('/bulk-delete', [ProductController::class, 'bulkDestroy']);
    Route::post('/bulk-category', [ProductController::class, 'bulkAssignCategory']);
});
