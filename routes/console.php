<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Nothing synced by the end of an athlete's day means missed. The command
// picks the athletes for whom it is the small hours, so it runs every hour.
Schedule::command('coach:nightly')->hourly()->withoutOverlapping()->onOneServer();
