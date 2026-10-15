<?php

use App\Http\Controllers\Api\MaibCallbackController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChargingController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/google', [AuthController::class, 'loginWithGoogle'])->middleware('throttle:auth');
Route::post('/auth/apple', [AuthController::class, 'loginWithApple'])->middleware('throttle:auth');
Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
// HTML form opened from the reset email (token in the URL authorizes the change).
Route::get('/password/reset/{token}', [PasswordResetController::class, 'showResetForm'])->middleware('throttle:30,1');
Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');
Route::get('/legal', [LegalController::class, 'config']);
Route::get('/legal/terms', [LegalController::class, 'terms']);
Route::get('/legal/privacy', [LegalController::class, 'privacy']);
Route::post('/maib/callback', [MaibCallbackController::class, 'handle'])
    ->middleware('throttle:120,1');

// Acces guest: harta + lista statiilor + tarif public (fara actiuni de sesiune).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/stations', [StationController::class, 'index']);
    Route::get('/tariff/current', [TariffController::class, 'current']);
});

Route::middleware(['auth:api', 'token.version'])->group(function () {
    // Account / privacy rights — available even when a new legal version must be accepted.
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/accept-legal', [AuthController::class, 'acceptLegal'])->middleware('throttle:auth');
    Route::get('/me/privacy-export', [AuthController::class, 'exportPersonalData'])->middleware('throttle:2,1');
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::post('/me/delete', [AuthController::class, 'deleteAccount'])->middleware('throttle:5,1');

    Route::middleware('legal.accepted')->group(function () {
        Route::post('/stations/resolve-qr', [StationController::class, 'resolveQr']);
        // Each call queues OCPP commands to the charger: cap per user so a loop cannot flood a station.
        Route::post('/stations/{station}/refresh-status', [StationController::class, 'refreshStatus'])->middleware('throttle:20,1');
        Route::post('/stations/{station}/reset-connector', [StationController::class, 'resetConnector'])->middleware('throttle:6,1');
        Route::post('/stations/{station}/unlock-connector', [StationController::class, 'unlockConnector'])->middleware('throttle:6,1');
        Route::post('/stations/{station}/favorite', [StationController::class, 'toggleFavorite']);
        Route::post('/charging/start', [ChargingController::class, 'start']);
        Route::post('/charging/resume', [ChargingController::class, 'resume']);
        Route::post('/charging/stop', [ChargingController::class, 'stop']);
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::get('/sessions/active', [SessionController::class, 'active']);
        Route::get('/sessions/summary', [SessionController::class, 'summary']);
        Route::get('/sessions/{session}/live', [SessionController::class, 'live']);
        Route::get('/payments/config', [PaymentController::class, 'config']);
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/topups', [WalletController::class, 'indexTopups']);
        Route::post('/wallet/topup-checkout', [WalletController::class, 'createTopupCheckout']);
        Route::post('/wallet/topups/{topup}/verify-payment', [WalletController::class, 'verifyTopupPayment']);
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download']);
    });
});
