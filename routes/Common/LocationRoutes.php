<?php

use App\Http\Controllers\Common\LocationController;
use Illuminate\Support\Facades\Route;

Route::get('common/countries', [LocationController::class, 'getCountries']);
Route::get('common/states', [LocationController::class, 'getStates']);
Route::get('common/couriers', [LocationController::class, 'getCouriers']);
