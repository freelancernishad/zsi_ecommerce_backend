<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Common\SystemSettings\SystemSettingController;

Route::prefix('public')->group(function () {
    Route::get('/system-setting', [SystemSettingController::class, 'getPublicSettings']);
});
