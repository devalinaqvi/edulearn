<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('quizzes:finalize')->everyMinute()->withoutOverlapping();

// Housekeeping, not a deadline: once a day is enough, and it only ever removes archived content
// that nothing depends on.
Schedule::command('lms:purge-trash')->dailyAt('03:30')->withoutOverlapping();
