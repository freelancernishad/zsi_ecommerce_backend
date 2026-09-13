<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\Orders\OrderController;

Route::prefix('user/orders')->group(function () {
    Route::middleware('auth:api')->get('/', [OrderController::class, 'index']);
    Route::get('/{id}', [OrderController::class, 'show']);
    Route::post('/place-order', [OrderController::class, 'placeOrder']);
});
