# devpulse/laravel

Laravel SDK for DevPulse — automatic error tracking, slow query detection, and queue failure capture for Laravel applications.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- A running DevPulse server

## Installation

```bash
composer require devpulse/laravel
```

The service provider is auto-discovered. No manual registration needed.

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=devpulse-config
```

Add your DSN to `.env`:

```env
DEVPULSE_DSN=http://localhost:8000/api/ingest/YOUR_API_KEY
DEVPULSE_ENABLED=true
```

### All Config Options

| Variable                  | Default       | Description                                          |
|---------------------------|---------------|------------------------------------------------------|
| `DEVPULSE_DSN`            | —             | Ingest endpoint URL including your API key           |
| `DEVPULSE_ENABLED`        | `true`        | Enable / disable the SDK globally                    |
| `DEVPULSE_ASYNC`          | `true`        | Fire-and-forget HTTP transport (non-blocking)        |
| `DEVPULSE_TIMEOUT`        | `2`           | HTTP timeout in seconds                              |
| `DEVPULSE_SLOW_QUERY_MS`  | `1000`        | Log DB queries slower than this (ms)                 |
| `DEVPULSE_SLOW_REQUEST_MS`| `3000`        | Log HTTP requests slower than this (ms)              |

## What Gets Captured

By default the SDK captures:

- **Unhandled exceptions** — via Laravel's exception handler
- **Slow DB queries** — queries exceeding `DEVPULSE_SLOW_QUERY_MS`
- **Slow HTTP requests** — requests exceeding `DEVPULSE_SLOW_REQUEST_MS`
- **Queue job failures** — failed jobs with their exception context

These can be toggled individually in `config/devpulse.php` under the `capture` key.

## Manual Capture

```php
use DevPulse\Laravel\DevPulseFacade as DevPulse;

try {
    riskyOperation();
} catch (\Throwable $e) {
    DevPulse::captureException($e);
}

DevPulse::captureMessage('Quota limit approaching', 'warning');
```

## License

MIT — see [LICENSE](../../LICENSE)
