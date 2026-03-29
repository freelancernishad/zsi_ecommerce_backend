<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Common\CategoryController;
use App\Http\Controllers\Common\ProductController;

Route::prefix('ecommerce')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{slug}', [ProductController::class, 'show']);
});
