<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Common\CategoryController;
use App\Http\Controllers\Common\ProductController;
use App\Http\Controllers\Common\BrandController;
use App\Http\Controllers\Common\ActivityController;
use App\Http\Controllers\Common\ReviewController;

Route::prefix('ecommerce')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/suggestions', [ProductController::class, 'suggestions']);
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('products/filters', [ProductController::class, 'getFilters']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
    Route::post('reviews', [\App\Http\Controllers\Common\ReviewController::class, 'store'])->middleware('auth:api');
    Route::get('my-reviews', [\App\Http\Controllers\Common\ReviewController::class, 'index'])->middleware('auth:api');
    Route::get('pending-reviews', [\App\Http\Controllers\Common\ReviewController::class, 'pendingReviews'])->middleware('auth:api');
    Route::get('settings/{group}', [\App\Http\Controllers\Ecommerce\EcommerceSettingController::class, 'getSettingsByGroup']);
    Route::post('track', [ActivityController::class, 'track']);
});
