# DevPulse Laravel SDK

Real-time error tracking for Laravel — self-hosted and free.

## Installation

```bash
composer require devpulse/laravel
```

Publish the config:

```bash
php artisan vendor:publish --tag=devpulse-config
```

## Configuration

Add to `.env`:

```env
DEVPULSE_DSN=https://your-devpulse-server.com/api/ingest/your-api-key
DEVPULSE_ENV=production
DEVPULSE_RELEASE=1.4.2        # or set APP_VERSION — falls back to git SHA
```

### All options

| Variable | Default | Description |
|---|---|---|
| `DEVPULSE_DSN` | — | Ingest URL with API key (required) |
| `DEVPULSE_ENABLED` | `true` | Master on/off switch |
| `DEVPULSE_ENV` | `APP_ENV` | Environment name sent with events |
| `DEVPULSE_RELEASE` | `APP_VERSION` / git SHA | Release/version tag |
| `DEVPULSE_ASYNC` | `true` | Fire-and-forget HTTP (recommended) |
| `DEVPULSE_TIMEOUT` | `2` | HTTP timeout in seconds |
| `DEVPULSE_SAMPLE_RATE` | `1.0` | 0.0–1.0 fraction of events to send |
| `DEVPULSE_SLOW_QUERY_MS` | `1000` | Slow query threshold (ms) |
| `DEVPULSE_SLOW_REQUEST_MS` | `3000` | Slow request threshold (ms) |
| `DEVPULSE_MIN_LOG_LEVEL` | `error` | Minimum log level to capture |
| `DEVPULSE_USER_CONTEXT` | `true` | Attach auth user to events |

### Capture toggles

| Variable | Default | Description |
|---|---|---|
| `DEVPULSE_CAPTURE_EXCEPTIONS` | `true` | Unhandled exceptions |
| `DEVPULSE_CAPTURE_LOGS` | `true` | `Log::error()` / `Log::critical()` |
| `DEVPULSE_CAPTURE_SLOW_QUERIES` | `true` | Slow DB queries |
| `DEVPULSE_CAPTURE_SLOW_REQUESTS` | `true` | Slow HTTP requests (requires middleware) |
| `DEVPULSE_CAPTURE_QUEUE_FAILURES` | `true` | Failed queue jobs |
| `DEVPULSE_CAPTURE_COMMANDS` | `true` | Artisan command failures (non-zero exit) |

## What's captured automatically

- **Exceptions** — All unhandled exceptions (excluding ignored list)
- **Log::error / critical** — Laravel log entries at `error` level or above
- **Slow queries** — DB queries exceeding the threshold, plus all queries as breadcrumbs
- **Slow requests** — HTTP requests exceeding the threshold (add middleware)
- **Queue failures** — Failed jobs with queue, job class, and attempt count
- **Artisan failures** — Commands that exit with a non-zero code
- **User context** — Authenticated user ID, email, name (auto-detected)
- **Release** — Version tag from `DEVPULSE_RELEASE`, `APP_VERSION`, or git SHA
- **Breadcrumbs** — Last 20 queries and log entries attached to exceptions

## Ignored exceptions

The following are never reported by default (add more in `config/devpulse.php`):

- `ValidationException`
- `AuthenticationException`
- `AuthorizationException`
- `ModelNotFoundException`
- `NotFoundHttpException`
- `ThrottleRequestsException`
- `TokenMismatchException`

## Slow request middleware

Register in `app/Http/Kernel.php` (Laravel 10) or `bootstrap/app.php` (Laravel 11+):

```php
// Laravel 10 — app/Http/Kernel.php
protected $middleware = [
    \DevPulse\Laravel\Http\Middleware\DevPulseContext::class,
];

// Laravel 11 — bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\DevPulse\Laravel\Http\Middleware\DevPulseContext::class);
})
```

## Manual capture

```php
use DevPulse\Laravel\DevPulseFacade as DevPulse;

// Capture an exception manually
try {
    riskyOperation();
} catch (\Throwable $e) {
    DevPulse::capture($e, ['order_id' => $orderId]);
    throw $e;
}

// Capture a message
DevPulse::captureMessage('Payment gateway timeout', 'warning', [
    'gateway'     => 'stripe',
    'amount'      => $amount,
    'customer_id' => $customerId,
]);
```

## Testing

Use `DevPulse::fake()` to assert events in tests without hitting the server:

```php
use DevPulse\Laravel\DevPulseFacade as DevPulse;

public function test_order_failure_is_tracked(): void
{
    $fake = DevPulse::fake();

    $this->post('/orders', ['invalid' => 'data']);

    $fake->assertCaptured(\App\Exceptions\PaymentFailedException::class);
}

public function test_slow_payment_is_reported(): void
{
    $fake = DevPulse::fake();

    // ... trigger slow payment ...

    $fake->assertCapturedMessage('Slow request');
}

public function test_healthy_request_sends_nothing(): void
{
    $fake = DevPulse::fake();

    $this->get('/health');

    $fake->assertNothingCaptured();
}
```

## License

MIT
