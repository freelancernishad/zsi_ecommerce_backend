<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Controllers\Admin\UserManagement\AdminUserController;
use App\Http\Controllers\Admin\UserManagement\CustomerSegmentController;

Route::prefix('admin')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('customer-segments', [CustomerSegmentController::class, 'index']);
    Route::get('users/', [AdminUserController::class, 'index']);
    Route::get('user/{id}', [AdminUserController::class, 'show']);
    Route::get('user/{id}/logs', [AdminUserController::class, 'getLogs']);
    Route::patch('/user/{id}', [AdminUserController::class, 'update']);
    Route::patch('users/{id}/toggle-active', [AdminUserController::class, 'toggleActive']);
    Route::patch('users/{id}/toggle-block', [AdminUserController::class, 'toggleBlock']);
    Route::patch('users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);
    Route::patch('users/{id}/verify-email', [AdminUserController::class, 'verifyEmail']);
    Route::patch('users/{id}/update-notes', [AdminUserController::class, 'updateNotes']);
    Route::post('users/bulk-action', [AdminUserController::class, 'bulkAction']);
    Route::post('users/{id}/impersonate', [AdminUserController::class, 'impersonate']);
    Route::post('users/{id}/addresses', [AdminUserController::class, 'storeAddress']);
    Route::patch('addresses/{id}', [AdminUserController::class, 'updateAddress']);
    Route::post('addresses/{id}/set-default', [AdminUserController::class, 'setDefaultAddress']);
    Route::delete('addresses/{id}', [AdminUserController::class, 'destroyAddress']);
    Route::delete('users/{id}', [AdminUserController::class, 'destroy']);
});
