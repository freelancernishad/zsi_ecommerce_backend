<?php

use Amazon\Controllers\AmazonDatasetImportController;
use App\Http\Middleware\AuthenticateAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('api/admin/amazon/dataset')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('/config', [AmazonDatasetImportController::class, 'config']);
    Route::post('/preview', [AmazonDatasetImportController::class, 'preview']);
    Route::post('/import', [AmazonDatasetImportController::class, 'import']);
});
