<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChargingController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::get('/stations', [StationController::class, 'index']);
    Route::post('/charging/start', [ChargingController::class, 'start']);
    Route::post('/charging/stop', [ChargingController::class, 'stop']);
    Route::get('/sessions', [SessionController::class, 'index']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
