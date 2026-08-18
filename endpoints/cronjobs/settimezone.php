<?php

// PHP-FPM's default clear_env=yes setting strips container environment
// variables (like TZ) from worker processes, so getenv('TZ') here usually
// comes back empty even when `docker exec ... printenv TZ` shows it fine
// at the shell level — that's a different process. Falling through to
// date_default_timezone_get() then typically lands on UTC too, since the
// base image's php.ini doesn't set date.timezone either. Result: every
// "today" calculation in the app (paid/overdue status, calendar coloring,
// the 9am notification cron) silently runs on UTC instead of the
// configured local timezone, which is why day-rollover-dependent behavior
// was flipping at 8pm Eastern (= midnight UTC) instead of local midnight.
//
// EDIT THIS if you move to a different timezone.
$FALLBACK_TIMEZONE = 'America/New_York';

$timezone = getenv('TZ');
if ($timezone == '') {
    $timezone = date_default_timezone_get();
    if ($timezone == '' || $timezone == 'UTC') {
        $timezone = $FALLBACK_TIMEZONE;
    }
}

date_default_timezone_set($timezone);