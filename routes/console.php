<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune --model="App\\Models\\AuthAuditLog"')
    ->dailyAt('02:10')
    ->timezone(config('app.business_timezone', 'Asia/Jakarta'))
    ->withoutOverlapping();

Schedule::command('exports:cleanup')
    ->dailyAt('02:20')
    ->timezone(config('app.business_timezone', 'Asia/Jakarta'))
    ->withoutOverlapping();

Schedule::command('local-invoices:send-delivery-reminders')
    ->dailyAt('08:00')
    ->timezone(config('app.business_timezone', 'Asia/Jakarta'))
    ->withoutOverlapping();
