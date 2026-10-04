<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Presence ping
    |--------------------------------------------------------------------------
    |
    | How often a device reports that it is still powered, and how long the
    | dashboard waits before calling it gone. Detection can never be quicker
    | than the interval, and the window must stay comfortably above it: every
    | ping that arrives late enough to cross the window flips the badge off
    | and straight back on.
    |
    | On `php artisan serve` the window cannot safely go much below 6s. The
    | server is single-threaded, so a ping queues behind any page render and
    | arrives seconds late. Measured there: a 3s window mis-fired on 14% of
    | intervals, a 6s window on 3%. Behind a multi-worker server (nginx with
    | php-cgi, or Octane) pings land in milliseconds and 3 is realistic.
    |
    | Keep PRESENCE_PING_INTERVAL in step with PRESENCE_PING_INTERVAL_MS in
    | the firmware's include/config.h.
    |
    */

    'ping_interval_seconds' => (int) env('PRESENCE_PING_INTERVAL', 1),

    'offline_after_seconds' => (int) env('PRESENCE_OFFLINE_AFTER', 6),

];
