<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChargingController;
use App\Http\Controllers\Api\DevicePushController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RegistrationRequestController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/register-request', [RegistrationRequestController::class, 'store'])->middleware('throttle:10,1');
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

Route::middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::get('/stations', [StationController::class, 'index']);
    Route::post('/stations/resolve-qr', [StationController::class, 'resolveQr']);
    Route::post('/stations/{station}/refresh-status', [StationController::class, 'refreshStatus']);
    Route::post('/stations/{station}/reset-connector', [StationController::class, 'resetConnector']);
    Route::post('/stations/{station}/unlock-connector', [StationController::class, 'unlockConnector']);
    Route::post('/stations/{station}/favorite', [StationController::class, 'toggleFavorite']);
    Route::get('/tariff/current', [TariffController::class, 'current']);
    Route::post('/charging/start', [ChargingController::class, 'start']);
    Route::post('/charging/stop', [ChargingController::class, 'stop']);
    Route::post('/device-push/register', [DevicePushController::class, 'register']);
    Route::post('/device-push/unregister', [DevicePushController::class, 'unregister']);
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::get('/sessions/{session}/live', [SessionController::class, 'live']);
    Route::get('/sessions/{session}/stream', [SessionController::class, 'stream']);
    Route::get('/payments/config', [PaymentController::class, 'config']);
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::post('/wallet/topup-checkout', [WalletController::class, 'createTopupCheckout']);
    if (app()->environment('local')) {
        Route::post('/wallet/local-topup', [WalletController::class, 'localTopup']);
    }
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download']);
    Route::post('/invoices/{invoice}/checkout-session', [InvoiceController::class, 'createCheckoutSession']);
    Route::post('/invoices/{invoice}/verify-payment', [InvoiceController::class, 'verifyPayment']);
});
