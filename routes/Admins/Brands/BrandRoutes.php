<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\BrandController;

Route::middleware('auth:admin')->prefix('admin')->group(function () {
    Route::apiResource('brands', BrandController::class);
});
