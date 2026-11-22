<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('outbox:process')->everyMinute()->withoutOverlapping();
Schedule::command('queue:work database --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
Schedule::command('tasks:sla-scan')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('system:heartbeat')->everyMinute()->withoutOverlapping();
