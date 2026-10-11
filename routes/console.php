<?php

use Illuminate\Support\Facades\Schedule;

// The schedule runs only in the worker container (docker compose --profile worker), never in the app.
// Times are UTC.

Schedule::command('tkcrm:prune-notifications')->dailyAt('03:25')->withoutOverlapping(10);
