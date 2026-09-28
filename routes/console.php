<?php

use Illuminate\Support\Facades\Schedule;

// Shared hosting (Hostinger) has no long-running worker: a single cron entry
// "* * * * * php artisan schedule:run" processes the queued e-mails every minute.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
