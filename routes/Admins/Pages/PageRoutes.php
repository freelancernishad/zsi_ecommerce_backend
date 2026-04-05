<?php

use App\Http\Controllers\Admin\Pages\PageController;
use App\Http\Middleware\AuthenticateAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/pages')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/', [PageController::class, 'index']);
    Route::post('/', [PageController::class, 'store']);
    Route::get('/{id}', [PageController::class, 'show']);
    Route::put('/{id}', [PageController::class, 'update']);
    Route::delete('/{id}', [PageController::class, 'destroy']);
});
