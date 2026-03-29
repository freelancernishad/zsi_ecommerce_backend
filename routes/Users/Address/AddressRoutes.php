<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\User\AddressManagement\AddressController;

Route::prefix('user/addresses')->middleware('auth:api')->group(function () {
    Route::get('/', [AddressController::class, 'index']);
    Route::post('/', [AddressController::class, 'store']);
    Route::put('/{id}', [AddressController::class, 'update']);
    Route::delete('/{id}', [AddressController::class, 'destroy']);
});
