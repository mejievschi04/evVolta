<?php

use Illuminate\Console\Scheduling\Schedule;

app(Schedule::class)
    ->command('billing:generate-monthly')
    ->timezone('Europe/Chisinau')
    ->monthlyOn(1, '00:10');

app(Schedule::class)
    ->command('reservations:process')
    ->everyMinute();

app(Schedule::class)
    ->command('privacy:purge-expired')
    ->timezone('Europe/Chisinau')
    ->dailyAt('03:20');

// Plasa de siguranta daca callback-ul MAIB nu ajunge si clientul nu revine in aplicatie.
app(Schedule::class)
    ->command('wallet:reconcile-topups')
    ->everyFiveMinutes()
    ->withoutOverlapping();
