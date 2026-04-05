<?php

use App\Http\Controllers\Admin\AI\AIController;
use App\Http\Middleware\AuthenticateAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/ai')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::post('/generate-content', [AIController::class, 'generateContent']);
});
