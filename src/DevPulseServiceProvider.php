<?php

namespace DevPulse\Laravel;

use DevPulse\Client;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

class DevPulseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/devpulse.php', 'devpulse');

        $this->app->singleton('devpulse', function ($app) {
            $config = $app['config']['devpulse'];
            $dsn    = $config['dsn'] ?? '';
            $enabled = ($config['enabled'] ?? true) && !empty($dsn);

            return new Client([
                'dsn'     => $enabled ? $dsn : 'http://localhost/noop',
                'enabled' => $enabled,
                'async'   => $config['async']   ?? true,
                'timeout' => (int) ($config['timeout'] ?? 2),
            ]);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/devpulse.php' => config_path('devpulse.php'),
            ], 'devpulse-config');
        }

        $config = $this->app['config']['devpulse'];

        if (!($config['enabled'] ?? true) || empty($config['dsn'] ?? '')) {
            return;
        }

        // Breadcrumb buffer (shared across listeners for this request)
        $breadcrumbs     = [];
        $livewireContext = [];

        if ($config['capture']['exceptions'] ?? true) {
            $this->registerExceptionCapture($config, $breadcrumbs, $livewireContext);
        }

        if ($config['capture']['slow_queries'] ?? true) {
            $this->registerSlowQueryCapture($config, $breadcrumbs);
        }

        if ($config['capture']['queue_failures'] ?? true) {
            $this->registerQueueFailureCapture($config);
        }

        if ($config['capture']['logs'] ?? true) {
            $this->registerLogCapture($config, $breadcrumbs);
        }

        if ($config['capture']['commands'] ?? true) {
            $this->registerCommandCapture($config);
        }

        if ($config['capture']['livewire'] ?? true) {
            $this->registerLivewireCapture($config, $breadcrumbs, $livewireContext);
        }
    }

    // ── Exception capture ────────────────────────────────────────────────────

    private function registerExceptionCapture(array $config, array &$breadcrumbs, array &$livewireContext): void
    {
        $ignored        = $config['ignored_exceptions'] ?? [];
        $sampleRate     = (float) ($config['sample_rate'] ?? 1.0);
        $userContext    = $config['user_context'] ?? true;
        $captureCommands = $config['capture']['commands'] ?? true;

        $this->callAfterResolving(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            function ($handler) use ($ignored, $sampleRate, $userContext, $captureCommands, &$breadcrumbs, &$livewireContext) {
                $handler->reportable(function (Throwable $e) use ($ignored, $sampleRate, $userContext, $captureCommands, &$breadcrumbs, &$livewireContext) {
                    // In console, registerCommandCapture handles exceptions with richer command context.
                    // Queue worker failures are covered separately by registerQueueFailureCapture.
                    // Must NOT return false here — that would stop the chain before registerCommandCapture
                    // can set $lastException.
                    if ($captureCommands && app()->runningInConsole()) {
                        return;
                    }

                    // Ignored exception classes
                    foreach ($ignored as $class) {
                        if ($e instanceof $class) {
                            return false;
                        }
                    }

                    // Sampling
                    if ($sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) > $sampleRate) {
                        return false;
                    }

                    $extra = $this->buildBaseContext();

                    // User context
                    if ($userContext) {
                        $extra['user'] = $this->resolveUser();
                    }

                    // Breadcrumbs
                    if (!empty($breadcrumbs)) {
                        $extra['breadcrumbs'] = array_slice(
                            $breadcrumbs,
                            -($this->app['config']['devpulse.breadcrumbs.max'] ?? 20)
                        );
                    }

                    // Livewire component context (populated by registerLivewireCapture)
                    if (!empty($livewireContext)) {
                        $extra['livewire'] = $livewireContext;
                    }

                    app('devpulse')->captureException($e, $extra);

                    return false; // don't prevent default reporting
                });
            }
        );
    }

    // ── Slow query capture ───────────────────────────────────────────────────

    private function registerSlowQueryCapture(array $config, array &$breadcrumbs): void
    {
        $threshold    = (int) ($config['slow_query_ms'] ?? 1000);
        $trackCrumbs  = $config['breadcrumbs']['queries'] ?? true;

        DB::listen(function ($query) use ($threshold, $trackCrumbs, &$breadcrumbs) {
            $ms = $query->time;

            // Always add to breadcrumb buffer for context on later exceptions
            if ($trackCrumbs) {
                $breadcrumbs[] = [
                    'type'      => 'query',
                    'timestamp' => now()->toISOString(),
                    'category'  => 'db',
                    'message'   => \Illuminate\Support\Str::limit($query->sql, 200),
                    'data'      => ['duration_ms' => $ms, 'connection' => $query->connectionName],
                    'level'     => $ms >= $threshold ? 'warning' : 'info',
                ];
            }

            if ($ms < $threshold) {
                return;
            }

            app('devpulse')->captureMessage('Slow query detected', 'warning', array_merge(
                $this->buildBaseContext(),
                [
                    'sql'           => $query->sql,
                    'bindings'      => $query->bindings,
                    'duration_ms'   => $ms,
                    'threshold_ms'  => $threshold,
                    'connection'    => $query->connectionName,
                ]
            ));
        });
    }

    // ── Queue failure capture ────────────────────────────────────────────────

    private function registerQueueFailureCapture(array $config): void
    {
        Queue::failing(function ($event) use ($config) {
            app('devpulse')->captureException($event->exception, array_merge(
                $this->buildBaseContext(),
                [
                    'queue'         => $event->job->getQueue(),
                    'job'           => $event->job->resolveName(),
                    'connection'    => $event->connectionName,
                    'attempts'      => $event->job->attempts(),
                    'payload'       => $event->job->payload(),
                ]
            ));
        });
    }

    // ── Log capture (error + critical) ───────────────────────────────────────

    private function registerLogCapture(array $config, array &$breadcrumbs): void
    {
        $minLevel    = $config['min_log_level'] ?? 'error';
        $trackCrumbs = $config['breadcrumbs']['logs'] ?? true;

        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $minIdx = array_search($minLevel, $levels, true);

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Log\Events\MessageLogged::class,
            function ($event) use ($levels, $minIdx, $trackCrumbs, &$breadcrumbs) {
                $idx = array_search($event->level, $levels, true);

                // Add to breadcrumbs regardless of min level
                if ($trackCrumbs) {
                    $breadcrumbs[] = [
                        'type'      => 'log',
                        'timestamp' => now()->toISOString(),
                        'category'  => 'log',
                        'message'   => \Illuminate\Support\Str::limit($event->message, 200),
                        'level'     => $event->level,
                    ];
                }

                if ($idx === false || $idx < $minIdx) {
                    return;
                }

                // Don't double-report exceptions — they're already caught above
                if (isset($event->context['exception']) && $event->context['exception'] instanceof Throwable) {
                    return;
                }

                app('devpulse')->captureMessage(
                    $event->message,
                    $event->level,
                    array_merge($this->buildBaseContext(), ['log_context' => $event->context])
                );
            }
        );
    }

    // ── Artisan command failure capture ──────────────────────────────────────

    private function registerCommandCapture(array $config): void
    {
        $lastException = null;
        $inCommand     = false;

        // Mark when we enter a command so the exception tracker below only fires in command context
        $this->app['events']->listen(
            CommandStarting::class,
            function () use (&$inCommand, &$lastException) {
                $inCommand     = true;
                $lastException = null;
            }
        );

        // Intercept exceptions that occur while a command is running
        $this->callAfterResolving(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            function ($handler) use (&$lastException, &$inCommand) {
                $handler->reportable(function (Throwable $e) use (&$lastException, &$inCommand) {
                    if ($inCommand) {
                        $lastException = $e;
                        // Suppress default logging — CommandFinished will capture via DevPulse
                        return false;
                    }
                    // Not in a command — don't interfere with other reportable callbacks
                });
            }
        );

        $this->app['events']->listen(CommandFinished::class, function (CommandFinished $event) use (&$lastException, &$inCommand) {
            $inCommand = false;

            if ($event->exitCode === 0) {
                $lastException = null;
                return;
            }

            // Skip framework maintenance commands
            $skip = ['up', 'down', 'list', 'help', 'env', 'tinker'];
            if (in_array($event->command, $skip, true)) {
                $lastException = null;
                return;
            }

            $context = array_merge($this->buildBaseContext(), [
                'command'   => $event->command,
                'exit_code' => $event->exitCode,
                'input'     => (string) $event->input,
            ]);

            if ($lastException !== null) {
                // Capture with full exception details + command context
                app('devpulse')->captureException($lastException, $context);
            } else {
                // Non-exception failure (e.g. return Command::FAILURE)
                app('devpulse')->captureMessage(
                    "Artisan command failed: {$event->command}",
                    'error',
                    $context
                );
            }

            $lastException = null;
        });
    }

    // ── Livewire component capture ───────────────────────────────────────────

    private function registerLivewireCapture(array $config, array &$breadcrumbs, array &$livewireContext): void
    {
        // Livewire is an optional peer dependency — skip silently if absent.
        if (!class_exists(\Livewire\Livewire::class)) {
            return;
        }

        // Guard: Livewire's ServiceProvider must have booted (Octane / test safety).
        if (!app()->bound(\Livewire\Mechanisms\ComponentRegistry::class)) {
            return;
        }

        $threshold   = (int) ($config['slow_livewire_ms'] ?? 500);
        $trackCrumbs = $config['breadcrumbs']['livewire'] ?? true;

        /** @var array<string, float> $actionTimers  key = "{componentId}::{action}" */
        $actionTimers = [];

        // Populate context on AJAX updates (component.hydrate).
        \Livewire\Livewire::listen('component.hydrate', function ($component) use (&$livewireContext): void {
            $livewireContext['livewire_component'] = $component->getName();
            $livewireContext['livewire_id']        = $component->getId();
        });

        // Populate context on initial full-page renders (component.mount).
        \Livewire\Livewire::listen('component.mount', function ($component) use (&$livewireContext): void {
            $livewireContext['livewire_component'] = $component->getName();
            $livewireContext['livewire_id']        = $component->getId();
        });

        // Record action start time and set action name in shared context.
        // If action.finish never fires (exception mid-action), livewire_action remains
        // in $livewireContext so the exception handler can include it automatically.
        \Livewire\Livewire::listen('action.start', function ($component, $action) use (&$livewireContext, &$actionTimers): void {
            // Safety valve: prevent unbounded timer growth under persistent workers (Octane).
            if (count($actionTimers) > 50) {
                $actionTimers = [];
            }

            $actionTimers[$component->getId() . '::' . $action] = microtime(true);
            $livewireContext['livewire_action'] = $action;
        });

        // Compute duration, record breadcrumb, and report if slow.
        \Livewire\Livewire::listen('action.finish', function ($component, $action) use (&$livewireContext, &$actionTimers, $threshold, $trackCrumbs, &$breadcrumbs): void {
            $key = $component->getId() . '::' . $action;
            $ms  = isset($actionTimers[$key])
                ? (microtime(true) - $actionTimers[$key]) * 1000
                : null;

            unset($actionTimers[$key]);

            if ($trackCrumbs) {
                $breadcrumbs[] = [
                    'type'      => 'livewire',
                    'timestamp' => now()->toISOString(),
                    'category'  => 'livewire',
                    'message'   => $component->getName() . '::' . $action . '()',
                    'data'      => array_filter([
                        'component'   => $component->getName(),
                        'action'      => $action,
                        'id'          => $component->getId(),
                        'duration_ms' => $ms !== null ? round($ms, 2) : null,
                    ]),
                    'level'     => ($ms !== null && $ms >= $threshold) ? 'warning' : 'info',
                ];
            }

            if ($ms !== null && $ms >= $threshold) {
                app('devpulse')->captureMessage(
                    sprintf('Slow Livewire action: %s::%s() (%.0fms)', $component->getName(), $action, $ms),
                    'warning',
                    array_merge(
                        $this->buildBaseContext(),
                        [
                            'livewire_component'   => $component->getName(),
                            'livewire_id'          => $component->getId(),
                            'livewire_action'      => $action,
                            'duration_ms'          => round($ms, 2),
                            'threshold_ms'         => $threshold,
                            'component_properties' => $this->safeComponentProperties($component),
                        ]
                    )
                );
            }

            // Clear the in-flight action name now that the action completed normally.
            unset($livewireContext['livewire_action']);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build context sent with every event: release, env, Laravel/PHP versions.
     */
    private function buildBaseContext(): array
    {
        $config  = $this->app['config']['devpulse'];
        $release = $config['release'] ?? null;

        // Fall back to git SHA
        if (empty($release)) {
            $release = $this->gitSha();
        }

        $ctx = [
            'platform'    => 'laravel',
            'environment' => $config['environment'] ?? app()->environment(),
            'php'         => PHP_VERSION,
            'laravel'     => app()->version(),
        ];

        if ($release) {
            $ctx['release'] = $release;
        }

        // HTTP request context (not available in console)
        // Note: 'request' key is intentionally omitted here — php-core's buildRequest()
        // owns that key and would overwrite anything set here via array_merge.
        // Routing is stored separately so it survives the merge.
        if (app()->bound('request') && app('request')->isMethod('get') !== null) {
            $request = app('request');
            $route   = $request->route();

            $ctx['routing'] = [
                'controller' => optional($route)->getActionName(),
                'name'       => optional($route)->getName(),
                'middleware' => optional($route)->gatherMiddleware() ?? [],
                'parameters' => optional($route)->parameters() ?? [],
            ];
        }

        return $ctx;
    }

    /**
     * Resolve the authenticated user (ID, email, name).
     * Never throws — returns null if auth is not available.
     */
    private function resolveUser(): ?array
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return null;
            }

            return array_filter([
                'id'    => $user->getAuthIdentifier(),
                'email' => method_exists($user, 'getEmailForVerification')
                    ? $user->getEmailForVerification()
                    : ($user->email ?? null),
                'name'  => $user->name ?? null,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the short git SHA of HEAD, if git is available.
     */
    private function gitSha(): ?string
    {
        try {
            $sha = trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null'));
            return !empty($sha) ? $sha : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reflect public non-static properties of a Livewire component into a
     * serialisable snapshot safe to send to DevPulse.
     *
     * Intentionally typed as `object` (not a Livewire class) so PHP does not
     * attempt to autoload Livewire when this method is compiled — it is only
     * ever called from within the class_exists guard in registerLivewireCapture.
     */
    private function safeComponentProperties(object $component): array
    {
        $result = [];

        try {
            foreach ((new \ReflectionClass($component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $name = $property->getName();

                try {
                    $value = $property->getValue($component);
                } catch (\Throwable) {
                    $result[$name] = '[uninitialized]';
                    continue;
                }

                if (is_scalar($value) || $value === null) {
                    $result[$name] = $value;
                } elseif (is_array($value)) {
                    $result[$name] = count($value) . ' items';
                } else {
                    $result[$name] = get_class($value);
                }
            }
        } catch (\Throwable) {
            // Reflection can fail on internal/anonymous classes — never surface this.
        }

        return $result;
    }
}
