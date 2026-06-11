<?php

use App\Http\Controllers\Backoffice\AuthController as BackofficeAuthController;
use App\Http\Controllers\Backoffice\DashboardController;
use App\Http\Controllers\Payments\StripeRedirectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('backoffice')->middleware('security.headers')->group(function () {
    Route::get('/', fn () => redirect()->route('backoffice.login'))->name('backoffice.root');
    Route::get('/csrf', fn () => response()->json(['token' => csrf_token()]))->name('backoffice.csrf');
    Route::get('/login', [BackofficeAuthController::class, 'showLogin'])->name('backoffice.login');
    Route::post('/login', [BackofficeAuthController::class, 'login'])->middleware('throttle:5,1')->name('backoffice.login.post');

    Route::middleware('backoffice.auth')->group(function () {
        Route::post('/logout', [BackofficeAuthController::class, 'logout'])->name('backoffice.logout');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('backoffice.dashboard');
        Route::get('/stations', [DashboardController::class, 'stations'])->name('backoffice.stations');
        Route::get('/stations/{station}', [DashboardController::class, 'showStation'])->name('backoffice.stations.show');
        Route::get('/audit-logs', [DashboardController::class, 'auditLogs'])->name('backoffice.audit_logs');
        Route::get('/audit-logs/{auditLog}', [DashboardController::class, 'auditLog'])->name('backoffice.audit_logs.show');
        Route::post('/stations', [DashboardController::class, 'storeStation'])->name('backoffice.stations.store');
        Route::post('/stations/{station}/update', [DashboardController::class, 'updateStation'])->name('backoffice.stations.update');
        Route::post('/stations/{station}/delete', [DashboardController::class, 'deleteStation'])->name('backoffice.stations.delete');
        Route::post('/stations/{station}/diagnostics', [DashboardController::class, 'requestStationDiagnostics'])->name('backoffice.stations.diagnostics');
        Route::post('/stations/{station}/refresh-status', [DashboardController::class, 'refreshStationStatus'])->name('backoffice.stations.refresh_status');
        Route::post('/stations/{station}/unlock-connector', [DashboardController::class, 'unlockStationConnector'])->name('backoffice.stations.unlock_connector');
        Route::post('/stations/{station}/stop-active-session', [DashboardController::class, 'stopActiveStationSession'])->name('backoffice.stations.stop_active_session');
        Route::get('/stations/{station}/qr-preview', [DashboardController::class, 'previewStationQr'])->name('backoffice.stations.qr.preview');
        Route::get('/stations/{station}/qr', [DashboardController::class, 'downloadStationQr'])->name('backoffice.stations.qr');
        Route::get('/sessions', [DashboardController::class, 'sessions'])->name('backoffice.sessions');
        Route::post('/sessions/{session}/stop', [DashboardController::class, 'stopSession'])->name('backoffice.sessions.stop');
        Route::post('/sessions/{session}/delete', [DashboardController::class, 'deleteSession'])->name('backoffice.sessions.delete');
        Route::get('/users', [DashboardController::class, 'users'])->name('backoffice.users');
        Route::get('/users/{user}', [DashboardController::class, 'showUser'])->name('backoffice.users.show');
        Route::post('/users', [DashboardController::class, 'storeUser'])->name('backoffice.users.store');
        Route::get('/registration-requests', [DashboardController::class, 'registrationRequests'])->name('backoffice.registration_requests');
        Route::post('/registration-requests/{registrationRequest}/approve', [DashboardController::class, 'approveRegistrationRequest'])->name('backoffice.registration_requests.approve');
        Route::post('/registration-requests/{registrationRequest}/reject', [DashboardController::class, 'rejectRegistrationRequest'])->name('backoffice.registration_requests.reject');
        Route::get('/wallet-topups', [DashboardController::class, 'walletTopups'])->name('backoffice.wallet_topups');
        Route::get('/invoices', [DashboardController::class, 'invoices'])->name('backoffice.invoices');
        Route::get('/invoices/{invoice}/download', [DashboardController::class, 'downloadInvoice'])->name('backoffice.invoices.download');
        Route::post('/invoices/{invoice}/send', [DashboardController::class, 'sendInvoice'])->name('backoffice.invoices.send');
        Route::post('/invoices/{invoice}/delete', [DashboardController::class, 'deleteInvoice'])->name('backoffice.invoices.delete');
        Route::post('/settings', [DashboardController::class, 'updateSettings'])->name('backoffice.settings.update');
        Route::post('/tariff', [DashboardController::class, 'updateTariff'])->name('backoffice.tariff.update');
    });
});

Route::get('/payments/stripe/success', [StripeRedirectController::class, 'success'])
    ->name('payments.stripe.success');

Route::get('/payments/stripe/cancel', [StripeRedirectController::class, 'cancel'])
    ->name('payments.stripe.cancel');
