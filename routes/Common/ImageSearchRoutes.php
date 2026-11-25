<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Common\ProductImageSearchController;

Route::prefix('ecommerce')->group(function () {
    Route::post('products/image-search', [ProductImageSearchController::class, 'search'])
        ->middleware('throttle:25,1');
});
