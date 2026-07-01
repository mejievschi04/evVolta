<?php

use Illuminate\Console\Scheduling\Schedule;

app(Schedule::class)
    ->command('billing:generate-monthly')
    ->timezone('Europe/Chisinau')
    ->monthlyOn(1, '00:10');

app(Schedule::class)
    ->command('reservations:process')
    ->everyMinute();
