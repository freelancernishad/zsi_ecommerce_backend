<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use CjDropshipping\Controllers\CjDropshippingController;

Route::prefix('api/admin/cj')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/search', [CjDropshippingController::class, 'search']);
    Route::get('/product/{pid}', [CjDropshippingController::class, 'detail']);
    Route::post('/import', [CjDropshippingController::class, 'import']);
    Route::post('/shipping-calculate', [CjDropshippingController::class, 'calculateShipping']);
    Route::post('/orders/{orderId}/fulfill', [CjDropshippingController::class, 'fulfillOrder']);
});
