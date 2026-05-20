<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:generate-monthly', function () {
    /** @var \App\Services\BillingService $billing */
    $billing = app(\App\Services\BillingService::class);
    $count = $billing->generateMonthlyInvoices();
    $this->info("Monthly invoices generated: {$count}");
})->purpose('Generate monthly invoices');

app(Schedule::class)
    ->command('billing:generate-monthly')
    ->timezone('Europe/Chisinau')
    ->monthlyOn(1, '00:10');
