<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Day-end sales summary to Telegram, once per branch-day. Needs `php artisan schedule:work` running (start.bat does it).
Schedule::command('reports:daily-summary')->everyFifteenMinutes()->withoutOverlapping();
