<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\Cart\CartController;

Route::prefix('user/cart')->middleware('auth:api')->group(function () {
    Route::get('/', [CartController::class, 'index']);
    Route::post('/add', [CartController::class, 'addToCart']);
    Route::put('/{id}', [CartController::class, 'updateQuantity']);
    Route::delete('/{id}', [CartController::class, 'removeFromCart']);
    Route::delete('/', [CartController::class, 'clearCart']);
});
