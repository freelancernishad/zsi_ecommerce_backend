<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Controllers\Common\SupportAndConnect\ContactMessageController;



Route::post('/contact/send', [ContactMessageController::class, 'send']);


Route::prefix('admin')->group(function () {
    Route::middleware(AuthenticateAdmin::class)->group(function () {

        Route::get('/contact-messages/success', [ContactMessageController::class, 'successfulAdminEmails']);
        Route::get('/contact-messages/single/{id}', [ContactMessageController::class, 'show']);
        Route::post('/contact-messages/{id}/reply', [ContactMessageController::class, 'reply']);
        Route::patch('/contact-messages/{id}/note', [ContactMessageController::class, 'updateNote']);

    });

});
