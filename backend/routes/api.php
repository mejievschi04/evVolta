<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChargingController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\RegistrationRequestController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\TariffController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register-request', [RegistrationRequestController::class, 'store'])->middleware('throttle:10,1');
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

Route::middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::get('/stations', [StationController::class, 'index']);
    Route::post('/stations/{station}/favorite', [StationController::class, 'toggleFavorite']);
    Route::get('/tariff/current', [TariffController::class, 'current']);
    Route::post('/charging/start', [ChargingController::class, 'start']);
    Route::post('/charging/stop', [ChargingController::class, 'stop']);
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download']);
    Route::post('/invoices/{invoice}/checkout-session', [InvoiceController::class, 'createCheckoutSession']);
    Route::post('/invoices/{invoice}/verify-payment', [InvoiceController::class, 'verifyPayment']);
});
