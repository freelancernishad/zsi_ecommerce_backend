<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Common\CategoryController;
use App\Http\Controllers\Common\ProductController;
use App\Http\Controllers\Common\BrandController;

Route::prefix('ecommerce')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('products/filters', [ProductController::class, 'getFilters']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::post('reviews', [\App\Http\Controllers\Common\ReviewController::class, 'store'])->middleware('auth:sanctum');
    Route::get('settings/{group}', [\App\Http\Controllers\Ecommerce\EcommerceSettingController::class, 'getSettingsByGroup']);
});
