<?php

use App\Schedule\ScheduledJobs;

// The schedule runs only in the worker container (docker compose --profile worker), never in the app.
// The jobs, and when each runs, are listed in App\Schedule\ScheduledJobs.

ScheduledJobs::register();
