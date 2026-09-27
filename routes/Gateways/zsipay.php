<?php

use App\Http\Controllers\Gateways\ZsiPayController;
use Illuminate\Support\Facades\Route;

Route::post('/checkout', [ZsiPayController::class, 'checkout']);
Route::post('/webhook', [ZsiPayController::class, 'webhook']);
