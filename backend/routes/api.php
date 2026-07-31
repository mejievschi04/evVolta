<?php

use App\Http\Controllers\Api\MaibCallbackController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChargingController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::get('/legal', [LegalController::class, 'config']);
Route::get('/legal/terms', [LegalController::class, 'terms']);
Route::get('/legal/privacy', [LegalController::class, 'privacy']);
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
    ->middleware('throttle:120,1');
Route::post('/maib/callback', [MaibCallbackController::class, 'handle'])
    ->middleware('throttle:120,1');

Route::middleware('auth:api')->group(function () {
    // Account / privacy rights — available even when a new legal version must be accepted.
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,1');
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/accept-legal', [AuthController::class, 'acceptLegal'])->middleware('throttle:10,1');
    Route::get('/me/privacy-export', [AuthController::class, 'exportPersonalData'])->middleware('throttle:2,1');
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/me/delete', [AuthController::class, 'deleteAccount'])->middleware('throttle:5,1');

    Route::middleware('legal.accepted')->group(function () {
        Route::get('/stations', [StationController::class, 'index']);
        Route::post('/stations/resolve-qr', [StationController::class, 'resolveQr']);
        Route::post('/stations/{station}/refresh-status', [StationController::class, 'refreshStatus']);
        Route::post('/stations/{station}/reset-connector', [StationController::class, 'resetConnector']);
        Route::post('/stations/{station}/unlock-connector', [StationController::class, 'unlockConnector']);
        Route::post('/stations/{station}/favorite', [StationController::class, 'toggleFavorite']);
        Route::get('/stations/{station}/reservations/availability', [ReservationController::class, 'availability']);
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
        Route::post('/reservations/{reservation}/verify-plug', [ReservationController::class, 'verifyPlug']);
        Route::get('/tariff/current', [TariffController::class, 'current']);
        Route::post('/charging/start', [ChargingController::class, 'start']);
        Route::post('/charging/resume', [ChargingController::class, 'resume']);
        Route::post('/charging/stop', [ChargingController::class, 'stop']);
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::get('/sessions/{session}/live', [SessionController::class, 'live']);
        Route::get('/sessions/{session}/stream', [SessionController::class, 'stream']);
        Route::get('/payments/config', [PaymentController::class, 'config']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/topups', [WalletController::class, 'indexTopups']);
        Route::post('/wallet/topup-checkout', [WalletController::class, 'createTopupCheckout']);
        Route::post('/wallet/topups/{topup}/verify-payment', [WalletController::class, 'verifyTopupPayment']);
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download']);
        Route::post('/invoices/{invoice}/checkout-session', [InvoiceController::class, 'createCheckoutSession']);
        Route::post('/invoices/{invoice}/verify-payment', [InvoiceController::class, 'verifyPayment']);
    });
});
