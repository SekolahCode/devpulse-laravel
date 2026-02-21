<?php

return [
    'dsn'         => env('DEVPULSE_DSN', ''),
    'environment' => env('APP_ENV', 'production'),
    'release'     => env('APP_VERSION', null),
    'enabled'     => env('DEVPULSE_ENABLED', true),
    'async'       => env('DEVPULSE_ASYNC', true),
    'timeout'     => env('DEVPULSE_TIMEOUT', 2),

    // Performance thresholds (in milliseconds)
    'slow_query_ms'   => env('DEVPULSE_SLOW_QUERY_MS', 1000),
    'slow_request_ms' => env('DEVPULSE_SLOW_REQUEST_MS', 3000),

    // What to capture
    'capture' => [
        'exceptions'    => true,
        'slow_queries'  => true,
        'slow_requests' => true,
        'queue_failures'=> true,
    ],
];
