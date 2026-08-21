<?php

namespace DevPulse\Laravel;

use DevPulse\Client;
use DevPulse\Laravel\Console\Commands\ReleaseCommand;
use DevPulse\Laravel\Console\Commands\TestCommand;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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

        // Scoped (not a plain singleton): Octane calls Container::forgetScopedInstances()
        // at the request boundary, so the next app(ContextStore::class) resolution
        // gets a fresh, empty store automatically. Every listener below MUST resolve
        // this fresh each time it fires rather than capturing one instance up front —
        // see ContextStore's docblock.
        $this->app->scoped(ContextStore::class, function ($app) {
            $maxCrumbs = (int) ($app['config']['devpulse']['breadcrumbs']['max'] ?? 20);

            return new ContextStore($maxCrumbs);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/devpulse.php' => config_path('devpulse.php'),
            ], 'devpulse-config');

            $this->commands([
                ReleaseCommand::class,
                TestCommand::class,
            ]);
        }

        $config = $this->app['config']['devpulse'];

        if (!($config['enabled'] ?? true) || empty($config['dsn'] ?? '')) {
            return;
        }

        if ($config['capture']['exceptions'] ?? true) {
            $this->registerExceptionCapture($config);
        }

        if ($config['capture']['slow_queries'] ?? true) {
            $this->registerSlowQueryCapture($config);
        }

        if ($config['capture']['queue_failures'] ?? true) {
            $this->registerQueueFailureCapture($config);
        }

        if ($config['capture']['logs'] ?? true) {
            $this->registerLogCapture($config);
        }

        if ($config['capture']['commands'] ?? true) {
            $this->registerCommandCapture($config);
        }

        if ($config['capture']['livewire'] ?? true) {
            $this->registerLivewireCapture($config);
        }
    }

    /** Resolve the current request's ContextStore. Always call this fresh — never cache the result. */
    private function store(): ContextStore
    {
        return $this->app->make(ContextStore::class);
    }

    // ── Exception capture ────────────────────────────────────────────────────

    private function registerExceptionCapture(array $config): void
    {
        $ignored         = $config['ignored_exceptions'] ?? [];
        $sampleRate      = (float) ($config['sample_rate'] ?? 1.0);
        $userContext     = $config['user_context'] ?? true;
        $captureCommands = $config['capture']['commands'] ?? true;

        $this->callAfterResolving(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            function ($handler) use ($ignored, $sampleRate, $userContext, $captureCommands) {
                $handler->reportable(function (Throwable $e) use ($ignored, $sampleRate, $userContext, $captureCommands) {
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

                    // Breadcrumbs, Livewire context, and any manually-added tags/context
                    // (DevPulse::addBreadcrumb()/setTag()/setContext()).
                    $extra = $this->store()->applyTo($extra);

                    app('devpulse')->captureException($this->safeThrowable($e), $extra);
                    // Returning nothing (not false) allows Laravel's default reporting to continue.
                });
            }
        );
    }

    // ── Slow query capture ───────────────────────────────────────────────────

    private function registerSlowQueryCapture(array $config): void
    {
        $threshold   = (int) ($config['slow_query_ms'] ?? 1000);
        $trackCrumbs = $config['breadcrumbs']['queries'] ?? true;

        DB::listen(function ($query) use ($threshold, $trackCrumbs) {
            $ms = $query->time;

            // Always add to breadcrumb buffer for context on later exceptions
            if ($trackCrumbs) {
                $this->store()->addBreadcrumb(
                    \Illuminate\Support\Str::limit($query->sql, 200),
                    'db',
                    ['duration_ms' => $ms, 'connection' => $query->connectionName],
                    $ms >= $threshold ? 'warning' : 'info'
                );
            }

            if ($ms < $threshold) {
                return;
            }

            // Redact string bindings — they may contain passwords, tokens, or PII.
            // Numeric and boolean values are kept as they are typically IDs or flags.
            $safeBindings = array_map(
                fn ($v) => is_string($v) ? '?' : $v,
                $query->bindings
            );

            app('devpulse')->captureMessage('Slow query detected', 'warning', array_merge(
                $this->buildBaseContext(),
                [
                    'sql'           => $query->sql,
                    'bindings'      => $safeBindings,
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
        Queue::failing(function ($event) {
            app('devpulse')->captureException($this->safeThrowable($event->exception), array_merge(
                $this->buildBaseContext(),
                [
                    'queue'      => $event->job->getQueue(),
                    'job'        => $event->job->resolveName(),
                    'connection' => $event->connectionName,
                    'attempts'   => $event->job->attempts(),
                    // Raw job payload is omitted — it may contain passwords, tokens,
                    // or other secrets passed as constructor arguments.
                ]
            ));
        });
    }

    // ── Log capture (error + critical) ───────────────────────────────────────

    private function registerLogCapture(array $config): void
    {
        $minLevel    = $config['min_log_level'] ?? 'error';
        $trackCrumbs = $config['breadcrumbs']['logs'] ?? true;

        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $minIdx = array_search($minLevel, $levels, true);

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Log\Events\MessageLogged::class,
            function ($event) use ($levels, $minIdx, $trackCrumbs) {
                $idx = array_search($event->level, $levels, true);

                // Add to breadcrumbs regardless of min level
                if ($trackCrumbs) {
                    $this->store()->addBreadcrumb(
                        \Illuminate\Support\Str::limit($event->message, 200),
                        'log',
                        [],
                        $event->level
                    );
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

            // Redact values of options whose names suggest sensitive data.
            // Covers --password, --pwd, --pass, --passwd, --secret, --token,
            // --key, --auth, --api-key, --private, and space-separated variants.
            // The value alternation tries a quoted string first so secrets
            // containing spaces (e.g. --password="hunter 2") are redacted in
            // full instead of leaking everything after the first space.
            $safeInput = preg_replace(
                '/(--(?:password|pwd|pass|passwd|secret|token|key|auth|api[_-]?key|private)[^=\s]*(?:=|\s+))(?:"[^"]*"|\'[^\']*\'|\S+)/i',
                '$1[REDACTED]',
                (string) $event->input
            );

            $context = array_merge($this->buildBaseContext(), [
                'command'   => $event->command,
                'exit_code' => $event->exitCode,
                'input'     => $safeInput,
            ]);

            if ($lastException !== null) {
                // Capture with full exception details + command context
                app('devpulse')->captureException($this->safeThrowable($lastException), $context);
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

    private function registerLivewireCapture(array $config): void
    {
        // Livewire is an optional peer dependency — skip silently if absent.
        if (!class_exists(\Livewire\Livewire::class)) {
            return;
        }

        // Guard: Livewire's ServiceProvider must have booted (Octane / test safety).
        /** @phpstan-ignore-next-line */
        if (!app()->bound(\Livewire\Mechanisms\ComponentRegistry::class)) {
            return;
        }

        $threshold   = (int) ($config['slow_livewire_ms'] ?? 500);
        $trackCrumbs = $config['breadcrumbs']['livewire'] ?? true;

        /** @var array<string, float> $actionTimers  key = "{componentId}::{action}" */
        $actionTimers = [];

        // Populate context on AJAX updates (component.hydrate).
        \Livewire\Livewire::listen('component.hydrate', function ($component): void {
            $this->store()->setLivewire('livewire_component', $component->getName());
            $this->store()->setLivewire('livewire_id', $component->getId());
        });

        // Populate context on initial full-page renders (component.mount).
        \Livewire\Livewire::listen('component.mount', function ($component): void {
            $this->store()->setLivewire('livewire_component', $component->getName());
            $this->store()->setLivewire('livewire_id', $component->getId());
        });

        // Record action start time and set action name in shared context.
        // If action.finish never fires (exception mid-action), livewire_action remains
        // in the store so the exception handler can include it automatically.
        \Livewire\Livewire::listen('action.start', function ($component, $action) use (&$actionTimers): void {
            // Safety valve: prevent unbounded timer growth under persistent workers (Octane).
            if (count($actionTimers) > 50) {
                $actionTimers = [];
            }

            $actionTimers[$component->getId() . '::' . $action] = microtime(true);
            $this->store()->setLivewire('livewire_action', $action);
        });

        // Compute duration, record breadcrumb, and report if slow.
        \Livewire\Livewire::listen('action.finish', function ($component, $action) use (&$actionTimers, $threshold, $trackCrumbs): void {
            $key = $component->getId() . '::' . $action;
            $ms  = isset($actionTimers[$key])
                ? (microtime(true) - $actionTimers[$key]) * 1000
                : null;

            unset($actionTimers[$key]);

            if ($trackCrumbs) {
                $this->store()->addBreadcrumb(
                    $component->getName() . '::' . $action . '()',
                    'livewire',
                    array_filter([
                        'component'   => $component->getName(),
                        'action'      => $action,
                        'id'          => $component->getId(),
                        'duration_ms' => $ms !== null ? round($ms, 2) : null,
                    ]),
                    ($ms !== null && $ms >= $threshold) ? 'warning' : 'info'
                );
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
            $this->store()->clearLivewireKey('livewire_action');
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Laravel's own QueryException::formatMessage() substitutes every binding
     * directly into the SQL and bakes the result into the exception's message
     * property — before this SDK ever sees it. That means ->getMessage() on
     * a QueryException can already contain real bound values: passwords,
     * tokens, full session/request blobs a package like Telescope inserts as
     * a single JSON-encoded parameter, anything. The dedicated redaction in
     * registerSlowQueryCapture() only covers the deliberate "slow query"
     * report it builds itself — it never sees a thrown QueryException's
     * already-formatted message, so it does nothing here.
     *
     * Rebuilds a safe message from the exception's own accessors (the
     * original driver error, connection name, and the SQL with bindings
     * redacted the same way registerSlowQueryCapture() does), then wraps it
     * in a plain \Exception rather than mutating the original — Laravel's
     * own default logging may still process the original object after this
     * reportable callback returns, and it should keep full detail. A plain
     * \Exception is used (not a clone) because QueryException extends
     * PDOException, which PHP does not allow cloning at all ("Trying to
     * clone an uncloneable object"). file/line/trace are copied via
     * reflection so the stack trace captured in the dashboard still points
     * at the real query call site rather than here. Non-QueryException
     * throwables pass through unchanged.
     */
    private function safeThrowable(Throwable $e): Throwable
    {
        if (!$e instanceof QueryException) {
            return $e;
        }

        // Str::replaceArray() wants a list of strings — redacted string bindings
        // become the literal '?' (indistinguishable from an unfilled placeholder,
        // which is the point); everything else is cast to its string form so a
        // numeric ID or bool stays useful for debugging without leaking anything.
        $safeBindings = array_map(
            fn ($v) => is_string($v) ? '?' : (string) ($v ?? 'NULL'),
            $e->getBindings()
        );

        $safeSql = Str::replaceArray('?', $safeBindings, $e->getSql());

        $driverMessage = $e->getPrevious()?->getMessage() ?? 'Query error';

        $safeMessage = "{$driverMessage} (Connection: {$e->getConnectionName()}, SQL: {$safeSql})";

        $safe = new \Exception($safeMessage, $e->getCode());

        foreach ([
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTrace(),
        ] as $property => $value) {
            $reflection = new \ReflectionProperty(\Exception::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue($safe, $value);
        }

        return $safe;
    }

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
        if (app()->bound('request')) {
            $request = app('request');
            $route   = $request->route();

            $ctx['routing'] = [
                // @phpstan-ignore-next-line
                'controller' => optional($route)->getActionName() ?? null,
                // @phpstan-ignore-next-line
                'name'       => optional($route)->getName() ?? null,
                // @phpstan-ignore-next-line
                'middleware' => optional($route)->gatherMiddleware() ?? [],
                // @phpstan-ignore-next-line
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
            /** @phpstan-ignore-next-line */
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
     * Memoized per-worker: the git SHA only changes on deploy, but this is
     * called once per captured event, and a worker (classic or Octane) may
     * capture many events without a new deploy in between.
     */
    private static ?string $cachedGitSha = null;
    private static bool $gitShaResolved = false;

    private function gitSha(): ?string
    {
        if (self::$gitShaResolved) {
            return self::$cachedGitSha;
        }

        self::$gitShaResolved = true;

        try {
            $sha = trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null'));
            return self::$cachedGitSha = (!empty($sha) ? $sha : null);
        } catch (Throwable) {
            return self::$cachedGitSha = null;
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
