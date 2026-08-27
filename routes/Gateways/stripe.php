<?php

use App\Http\Controllers\Gateways\StripeController;
use App\Http\Controllers\Gateways\StripeWebhookController;
use App\Http\Middleware\AuthenticateUser;
use Illuminate\Support\Facades\Route;



// Webhook route - must be outside auth middleware

// stripe login
// stripe listen --forward-to http://192.168.0.100:8000/api/payment/stripe/webhook 
// stripe trigger payment_intent.succeeded

Route::post('/webhook', [StripeWebhookController::class, 'handle']); 

Route::middleware([AuthenticateUser::class])->group(function () {
    Route::post('/checkout', [StripeController::class, 'checkout']);
    Route::post('/subscribe', [StripeController::class, 'subscribe']);
    Route::post('/cancel-subscription', [StripeController::class, 'cancelSubscription']);
    Route::post('/payment-intent', [StripeController::class, 'paymentIntent']);
    Route::post('/update-payment-intent', [StripeController::class, 'updatePaymentIntent']);
    Route::post('/save-card', [StripeController::class, 'saveCard']);
    Route::get('/cards', [StripeController::class, 'getCards']);
    Route::post('/cards/confirm', [StripeController::class, 'confirmSaveCard']);
    Route::post('/cards/{id}/verify', [StripeController::class, 'verifyCard']);
    Route::post('/verify-card/{id}', [StripeController::class, 'verifyCard']);
    Route::post('/cards/{id}/set-default', [StripeController::class, 'setDefaultCard']);
    Route::delete('/cards/{id}', [StripeController::class, 'deleteCard']);
    Route::get('/payment-details/{session_id}', [StripeController::class, 'getPaymentDetails']);
});
