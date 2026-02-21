<?php

namespace DevPulse\Laravel;

use DevPulse\Client;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class DevPulseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/devpulse.php', 'devpulse');

        $this->app->singleton('devpulse', function (): Client {
            $raw = config('devpulse.dsn', '');
            $dsn = is_string($raw) ? $raw : '';

            // When DSN is absent we still create a Client so the binding always resolves
            // safely (e.g. from bootstrap/app.php or Tinker). Captures are no-ops because
            // enabled=false; the fake DSN only exists to satisfy the constructor's URL check.
            $enabled = $dsn !== '' && (bool) config('devpulse.enabled', true);

            return new Client([
                'dsn'         => $enabled ? $dsn : 'https://disabled.devpulse.local',
                'environment' => config('devpulse.environment'),
                'release'     => config('devpulse.release'),
                'enabled'     => $enabled,
                'async'       => config('devpulse.async'),
                'timeout'     => config('devpulse.timeout'),
            ]);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/devpulse.php' => config_path('devpulse.php'),
        ], 'devpulse-config');

        if (!config('devpulse.enabled') || !config('devpulse.dsn')) {
            return;
        }

        $this->captureExceptions();
        $this->captureSlowQueries();
        $this->captureQueueFailures();
    }

    // ── Exception Capture ───────────────────────────────────────────────────
    private function captureExceptions(): void
    {
        if (!config('devpulse.capture.exceptions')) {
            return;
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (\Throwable $e): void {
                    $this->app->make('devpulse')->captureException($e);
                });
            }
        });
    }

    // ── Slow Query Detection ─────────────────────────────────────────────────
    private function captureSlowQueries(): void
    {
        if (!config('devpulse.capture.slow_queries')) {
            return;
        }

        DB::listen(function ($query): void {
            $threshold = config('devpulse.slow_query_ms', 1000);
            if ($query->time < $threshold) {
                return;
            }

            $this->app->make('devpulse')->captureMessage(
                "Slow query ({$query->time}ms): {$query->sql}",
                'warning',
                [
                    'context' => [
                        'query'    => $query->sql,
                        'bindings' => $query->bindings,
                        'time_ms'  => $query->time,
                        'database' => config('database.default'),
                    ],
                ]
            );
        });
    }

    // ── Queue Job Failures ───────────────────────────────────────────────────
    private function captureQueueFailures(): void
    {
        if (!config('devpulse.capture.queue_failures')) {
            return;
        }

        Queue::failing(function (JobFailed $event): void {
            $this->app->make('devpulse')->captureException($event->exception, [
                'context' => [
                    'job'        => $event->job->getName(),
                    'queue'      => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                    'payload'    => $event->job->payload(),
                ],
            ]);
        });
    }
}
