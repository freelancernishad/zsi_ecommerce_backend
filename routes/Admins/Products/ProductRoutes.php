<?php
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Controllers\Admin\Products\ProductController;

Route::prefix('admin/products')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::post('/', [ProductController::class, 'store']);
    // Individual actions
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::put('/{id}', [ProductController::class, 'update']);
    Route::delete('/{id}', [ProductController::class, 'destroy']);

    // Bulk actions
    Route::post('/bulk-status', [ProductController::class, 'bulkUpdateStatus']);
    Route::post('/bulk-delete', [ProductController::class, 'bulkDestroy']);
});
