<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\Admin\PosController;

Route::prefix('admin/pos')->group(function () {
    Route::get('/scan', [PosController::class, 'scanBarcode']);
    Route::get('/products', [PosController::class, 'getProducts']);
    Route::post('/orders', [PosController::class, 'createOrder']);
    Route::get('/shift-summary', [PosController::class, 'getShiftSummary']);
});

Route::prefix('v1/admin/pos')->group(function () {
    Route::get('/scan', [PosController::class, 'scanBarcode']);
    Route::get('/products', [PosController::class, 'getProducts']);
    Route::post('/orders', [PosController::class, 'createOrder']);
    Route::get('/shift-summary', [PosController::class, 'getShiftSummary']);
});
