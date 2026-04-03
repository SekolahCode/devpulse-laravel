<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DSN (Data Source Name)
    |--------------------------------------------------------------------------
    | The ingest endpoint for your DevPulse server, including the API key.
    | Example: https://devpulse.example.com/api/ingest/your-api-key
    */
    'dsn' => env('DEVPULSE_DSN', ''),

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable
    |--------------------------------------------------------------------------
    */
    'enabled' => env('DEVPULSE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    | Defaults to APP_ENV. Sent with every event so you can filter by env.
    */
    'environment' => env('DEVPULSE_ENV', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | Release / Version
    |--------------------------------------------------------------------------
    | Tag events with the current deployment version.
    | Falls back to APP_VERSION, then the git SHA of HEAD.
    */
    'release' => env('DEVPULSE_RELEASE', env('APP_VERSION', null)),

    /*
    |--------------------------------------------------------------------------
    | Async (fire-and-forget)
    |--------------------------------------------------------------------------
    */
    'async' => env('DEVPULSE_ASYNC', true),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('DEVPULSE_TIMEOUT', 2),

    /*
    |--------------------------------------------------------------------------
    | Sample Rate
    |--------------------------------------------------------------------------
    | 1.0 = send everything, 0.5 = send 50%, 0.0 = send nothing.
    | Useful for high-traffic apps to reduce noise.
    */
    'sample_rate' => env('DEVPULSE_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Capture Toggles
    |--------------------------------------------------------------------------
    */
    'capture' => [
        'exceptions'    => env('DEVPULSE_CAPTURE_EXCEPTIONS',    true),
        'logs'          => env('DEVPULSE_CAPTURE_LOGS',          true),  // Log::error / critical
        'slow_queries'  => env('DEVPULSE_CAPTURE_SLOW_QUERIES',  true),
        'slow_requests' => env('DEVPULSE_CAPTURE_SLOW_REQUESTS', true),
        'queue_failures'=> env('DEVPULSE_CAPTURE_QUEUE_FAILURES',true),
        'commands'      => env('DEVPULSE_CAPTURE_COMMANDS',      true),  // Artisan failures
        'livewire'      => env('DEVPULSE_CAPTURE_LIVEWIRE',      true),  // Livewire component actions
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    */
    'slow_query_ms'    => env('DEVPULSE_SLOW_QUERY_MS',    1000),
    'slow_request_ms'  => env('DEVPULSE_SLOW_REQUEST_MS',  3000),
    'slow_livewire_ms' => env('DEVPULSE_SLOW_LIVEWIRE_MS',  500),

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    | These exception classes are never reported, even if capture.exceptions
    | is enabled. Add validation/auth exceptions you don't care about.
    */
    'ignored_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Database\Eloquent\ModelNotFoundException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
        \Illuminate\Session\TokenMismatchException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Log Levels
    |--------------------------------------------------------------------------
    | Log levels below this will not be captured. Valid values:
    | debug, info, notice, warning, error, critical, alert, emergency
    */
    'min_log_level' => env('DEVPULSE_MIN_LOG_LEVEL', 'error'),

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    | Attach recent activity (queries, logs) to exception events.
    */
    'breadcrumbs' => [
        'queries'  => env('DEVPULSE_BREADCRUMBS_QUERIES',  true),
        'logs'     => env('DEVPULSE_BREADCRUMBS_LOGS',     true),
        'livewire' => env('DEVPULSE_BREADCRUMBS_LIVEWIRE', true),
        'max'      => env('DEVPULSE_BREADCRUMBS_MAX',      20),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Context
    |--------------------------------------------------------------------------
    | Automatically attach the authenticated user to every event.
    | Set to false to disable.
    */
    'user_context' => env('DEVPULSE_USER_CONTEXT', true),

];
