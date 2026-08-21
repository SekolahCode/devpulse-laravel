# DevPulse Laravel SDK

Real-time error tracking for Laravel — self-hosted and free.

Requires a running **DevPulse server v1.0+** and PHP 8.1+.

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
DEVPULSE_DSN=https://your-devpulse-host/api/ingest/YOUR_API_KEY
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
| `DEVPULSE_SLOW_REQUEST_IGNORE` | `up,health,health*,_health*` | Comma-separated route names/paths to exclude from slow-request capture |
| `DEVPULSE_ADMIN_TOKEN` | — | Only for `devpulse:release` — the dashboard admin token (see below) |
| `DEVPULSE_PROJECT_ID` | — | Only for `devpulse:release` — this app's project UUID |

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

Health checks and monitoring endpoints hit on a tight interval by a load
balancer don't need to show up as "slow requests." Exclude them by route
name or path (wildcards supported) via `DEVPULSE_SLOW_REQUEST_IGNORE` or the
`slow_request_ignore` config array — `up` and `health*` are excluded by
default.

## Manual capture

Every automatic capture (queries, logs, Livewire actions) is attached to
the same per-request breadcrumb/context buffer these methods write to —
add your own business context anywhere in your app and it rides along with
whatever gets captured next, for the rest of the request:

```php
use DevPulse\Laravel\DevPulseFacade as DevPulse;

// Breadcrumbs — recent activity leading up to an error
DevPulse::addBreadcrumb('user reached payment step', 'checkout');

// Tags — short, indexable key/value pairs
DevPulse::setTag('tenant', $tenant->slug);

// Context — a named group of structured data
DevPulse::setContext('checkout', ['cart_id' => $cart->id, 'total' => $cart->total]);
```

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

## Artisan commands

### `devpulse:test`

Sends a real test event through your configured DSN, so you can confirm the
connection works before waiting for a real error:

```bash
php artisan devpulse:test
```

### `devpulse:release`

Registers a release with your DevPulse server so it shows up on the
project's Releases timeline — run it as part of your deploy pipeline:

```bash
php artisan devpulse:release 1.4.2 --ref=$(git rev-parse HEAD) --url=https://ci.example.com/builds/123
```

This needs two extra values beyond the DSN: `DEVPULSE_PROJECT_ID` (this
app's project UUID, visible in the dashboard URL) and `DEVPULSE_ADMIN_TOKEN`.

**`DEVPULSE_ADMIN_TOKEN` is a more sensitive credential than your DSN.** The
DSN's API key can only submit events for one project; the admin token is the
same one used to sign in to the DevPulse dashboard and grants access to
every project on your DevPulse instance. Treat it like any other
production secret — scope who can read the `.env` it lives in, don't commit
it, rotate it like you would any other admin credential.

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
