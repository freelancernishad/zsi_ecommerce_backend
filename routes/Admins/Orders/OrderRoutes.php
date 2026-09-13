<?php
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Controllers\Admin\Orders\OrderController;

Route::prefix('admin/orders')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/tax-reports', [\App\Http\Controllers\Admin\Reports\AdminTaxReportController::class, 'index']);
    Route::get('/stats', [OrderController::class, 'stats']);
    Route::get('/', [OrderController::class, 'index']);
    Route::post('/', [OrderController::class, 'store']);
    Route::get('/{id}', [OrderController::class, 'show']);
    Route::put('/{id}/status', [OrderController::class, 'updateStatus']);
    Route::post('/{id}/history', [OrderController::class, 'addStatusHistory']);
    Route::post('/{id}/timeline', [OrderController::class, 'addStatusHistory']);
});
