<?php

use App\Http\Controllers\Admin\Settings\ShippingMethodController;
use App\Http\Controllers\Admin\Settings\TaxController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/settings')->group(function () {
    // Tax Routes
    Route::get('/taxes', [TaxController::class, 'index']);
    Route::post('/taxes', [TaxController::class, 'store']);
    Route::get('/taxes/{id}', [TaxController::class, 'show']);
    Route::delete('/taxes/{id}', [TaxController::class, 'destroy']);

    // Stripe Tax Registrations (API Sync)
    Route::get('/stripe-tax-registrations', [\App\Http\Controllers\Admin\Settings\AdminStripeTaxRegistrationController::class, 'index']);
    Route::post('/stripe-tax-registrations', [\App\Http\Controllers\Admin\Settings\AdminStripeTaxRegistrationController::class, 'store']);
    Route::post('/stripe-tax-registrations/bulk', [\App\Http\Controllers\Admin\Settings\AdminStripeTaxRegistrationController::class, 'bulkAdd']);

    // Shipping Method Routes
    Route::get('/shipping-methods', [ShippingMethodController::class, 'index']);
    Route::post('/shipping-methods', [ShippingMethodController::class, 'store']);
    Route::get('/shipping-methods/{id}', [ShippingMethodController::class, 'show']);
    Route::delete('/shipping-methods/{id}', [ShippingMethodController::class, 'destroy']);

    // Site Wide Settings Routes (Universal & Scalable)
    Route::get('/site-settings/{group}', [\App\Http\Controllers\Admin\Settings\AdminSiteSettingController::class, 'index']);
    Route::post('/site-settings/update', [\App\Http\Controllers\Admin\Settings\AdminSiteSettingController::class, 'update']);
});
