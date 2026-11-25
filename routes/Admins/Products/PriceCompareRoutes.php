<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Controllers\Admin\Products\PriceCompareController;

Route::prefix('admin/price-compare')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::post('/search', [PriceCompareController::class, 'compare']);
});
