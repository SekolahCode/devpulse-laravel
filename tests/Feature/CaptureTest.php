<?php

namespace DevPulse\Laravel\Tests\Feature;

use DevPulse\Client;
use DevPulse\Laravel\DevPulseFacade as DevPulse;
use DevPulse\Laravel\Tests\TestCase;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Exercises the six register*Capture() listeners wired up in
 * DevPulseServiceProvider::boot(). Each test binds a mock 'devpulse' client
 * into the container, triggers the real Laravel event/mechanism the listener
 * hooks (reportable exception, DB query, queue job failure, log message,
 * console command lifecycle), and asserts on what reached the client.
 */
class CaptureTest extends TestCase
{
    /**
     * Extra config to apply on (re)boot, set by bootWith() before calling
     * refreshApplication(). ServiceProvider::boot() reads config into local
     * variables ONCE — a plain config([...]) call from inside a test method
     * runs after that snapshot was already taken and has no effect, so any
     * test that needs non-default config at boot time must go through
     * bootWith() instead of touching config() directly.
     *
     * @var array<string, mixed>
     */
    private array $extraDevpulseConfig = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        foreach ($this->extraDevpulseConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    /** Bind a PHPUnit mock Client into the container and return it. */
    private function mockClient(): Client
    {
        $client = $this->createMock(Client::class);
        $this->app->instance('devpulse', $client);

        return $client;
    }

    /**
     * Reboot the app with extra config applied before ServiceProvider::boot()
     * runs, then bind and return a fresh mock client. Use this instead of
     * mockClient() + config() whenever a test depends on a non-default
     * config value that boot() reads (ignored_exceptions, sample_rate,
     * slow_query_ms, capture toggles, etc).
     *
     * @param array<string, mixed> $config dot-notation devpulse.* keys
     */
    private function bootWith(array $config): Client
    {
        $this->extraDevpulseConfig = $config;
        $this->refreshApplication();

        return $this->mockClient();
    }

    private function report(\Throwable $e): void
    {
        $this->app->make(ExceptionHandler::class)->report($e);
    }

    // ── Exception capture ─────────────────────────────────────────────────

    // Note: registerExceptionCapture's reportable() closure silently defers
    // to registerCommandCapture whenever app()->runningInConsole() is true —
    // which it always is under Orchestra Testbench (PHP_SAPI === 'cli').
    // Real HTTP requests never hit that branch, so these tests disable
    // capture.commands to exercise the exception path the way a real
    // (non-console) request would. The command/console coordination itself
    // is covered separately by the "Command capture" tests below.

    public function test_exception_is_captured_by_default(): void
    {
        $client = $this->bootWith(['devpulse.capture.commands' => false]);
        $client->expects($this->once())
            ->method('captureException')
            ->with($this->isInstanceOf(\RuntimeException::class), $this->anything());

        $this->report(new \RuntimeException('boom'));
    }

    public function test_ignored_exception_class_is_not_captured(): void
    {
        $client = $this->bootWith([
            'devpulse.capture.commands'   => false,
            'devpulse.ignored_exceptions' => [\RuntimeException::class],
        ]);
        $client->expects($this->never())->method('captureException');

        $this->report(new \RuntimeException('boom'));
    }

    public function test_sample_rate_zero_never_captures(): void
    {
        $client = $this->bootWith([
            'devpulse.capture.commands' => false,
            'devpulse.sample_rate'      => 0.0,
        ]);
        $client->expects($this->never())->method('captureException');

        $this->report(new \RuntimeException('boom'));
    }

    public function test_exception_capture_disabled_via_config(): void
    {
        $client = $this->bootWith(['devpulse.capture.exceptions' => false]);
        $client->expects($this->never())->method('captureException');

        $this->report(new \RuntimeException('boom'));
    }

    public function test_query_breadcrumbs_are_attached_to_a_later_exception(): void
    {
        $client = $this->bootWith(['devpulse.capture.commands' => false]);
        $client->expects($this->once())
            ->method('captureException')
            ->with(
                $this->anything(),
                $this->callback(function (array $extra): bool {
                    return isset($extra['breadcrumbs'])
                        && collect($extra['breadcrumbs'])->contains(fn ($b) => $b['category'] === 'db');
                })
            );

        DB::select('select 1');
        $this->report(new \RuntimeException('boom'));
    }

    // ── Slow query capture ────────────────────────────────────────────────

    public function test_slow_query_triggers_capture_message(): void
    {
        $client = $this->bootWith(['devpulse.slow_query_ms' => 0]); // 0ms — every query qualifies as slow
        $client->expects($this->once())
            ->method('captureMessage')
            ->with($this->stringContains('Slow query'), 'warning', $this->anything());

        DB::select('select 1');
    }

    public function test_fast_query_does_not_trigger_capture_message(): void
    {
        $client = $this->bootWith(['devpulse.slow_query_ms' => 60_000]); // effectively unreachable
        $client->expects($this->never())->method('captureMessage');

        DB::select('select 1');
    }

    public function test_slow_query_redacts_string_bindings(): void
    {
        $client = $this->bootWith(['devpulse.slow_query_ms' => 0]);
        $client->expects($this->once())
            ->method('captureMessage')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $extra) => $extra['bindings'] === ['?'])
            );

        DB::select('select ? as x', ['sensitive-value']);
    }

    // ── Queue failure capture ─────────────────────────────────────────────

    public function test_queue_job_failure_is_captured_with_job_context(): void
    {
        $exception = new \RuntimeException('job blew up');

        /** @var Job&\PHPUnit\Framework\MockObject\MockObject $job */
        $job = $this->createMock(Job::class);
        $job->method('getQueue')->willReturn('emails');
        $job->method('resolveName')->willReturn('App\\Jobs\\SendWelcomeEmail');
        $job->method('attempts')->willReturn(3);

        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('captureException')
            ->with(
                $this->identicalTo($exception),
                $this->callback(fn (array $extra) => $extra['queue'] === 'emails'
                    && $extra['job'] === 'App\\Jobs\\SendWelcomeEmail'
                    && $extra['attempts'] === 3)
            );

        event(new JobFailed('redis', $job, $exception));
    }

    // ── Log capture ───────────────────────────────────────────────────────

    public function test_error_level_log_is_captured(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('captureMessage')
            ->with('Something broke', 'error', $this->anything());

        event(new MessageLogged('error', 'Something broke'));
    }

    public function test_log_below_min_level_is_not_captured(): void
    {
        $client = $this->bootWith(['devpulse.min_log_level' => 'error']);
        $client->expects($this->never())->method('captureMessage');

        event(new MessageLogged('warning', 'Just a warning'));
    }

    public function test_log_carrying_an_exception_is_not_double_reported(): void
    {
        // The exception handler's reportable() callback already captures this;
        // the log listener must not send a second, redundant event.
        $client = $this->mockClient();
        $client->expects($this->never())->method('captureMessage');

        event(new MessageLogged('error', 'Unhandled', ['exception' => new \RuntimeException('dup')]));
    }

    // ── Command capture ───────────────────────────────────────────────────

    /**
     * A real StringInput rather than a mocked InputInterface — PHPUnit's
     * mock generator configuring __toString() behaves differently across
     * the 10.x/11.x versions this package's CI matrix covers (10.5 refuses
     * to configure it at all: "does not exist, has not been specified, is
     * final, or is static"). A concrete Symfony object sidesteps that
     * entirely and StringInput::__toString() reconstructs quoted values
     * faithfully enough for the redaction tests below.
     */
    private function input(string $asString): InputInterface
    {
        return new \Symfony\Component\Console\Input\StringInput($asString);
    }

    public function test_non_zero_exit_without_exception_captures_a_message(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('captureMessage')
            ->with($this->stringContains('report:generate'), 'error', $this->anything());

        event(new CommandStarting('report:generate', $this->input('report:generate'), new NullOutput()));
        event(new CommandFinished('report:generate', $this->input('report:generate'), new NullOutput(), 1));
    }

    public function test_zero_exit_captures_nothing(): void
    {
        $client = $this->mockClient();
        $client->expects($this->never())->method('captureMessage');
        $client->expects($this->never())->method('captureException');

        event(new CommandStarting('report:generate', $this->input('report:generate'), new NullOutput()));
        event(new CommandFinished('report:generate', $this->input('report:generate'), new NullOutput(), 0));
    }

    public function test_maintenance_commands_are_skipped(): void
    {
        $client = $this->mockClient();
        $client->expects($this->never())->method('captureMessage');
        $client->expects($this->never())->method('captureException');

        event(new CommandStarting('up', $this->input('up'), new NullOutput()));
        event(new CommandFinished('up', $this->input('up'), new NullOutput(), 1));
    }

    public function test_exception_during_command_is_captured_with_command_context(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('captureException')
            ->with(
                $this->isInstanceOf(\RuntimeException::class),
                $this->callback(fn (array $extra) => $extra['command'] === 'report:generate' && $extra['exit_code'] === 1)
            );

        event(new CommandStarting('report:generate', $this->input('report:generate'), new NullOutput()));
        $this->report(new \RuntimeException('command blew up'));
        event(new CommandFinished('report:generate', $this->input('report:generate'), new NullOutput(), 1));
    }

    public function test_password_option_is_redacted(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('captureMessage')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $extra) => !str_contains($extra['input'], 'hunter2')
                    && str_contains($extra['input'], '[REDACTED]'))
            );

        $input = $this->input('report:generate --password=hunter2');
        event(new CommandStarting('report:generate', $input, new NullOutput()));
        event(new CommandFinished('report:generate', $input, new NullOutput(), 1));
    }

    public function test_quoted_password_with_spaces_is_fully_redacted(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('captureMessage')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn (array $extra) => !str_contains($extra['input'], 'hunter')
                    && !str_contains($extra['input'], '2"')
                    && str_contains($extra['input'], '[REDACTED]'))
            );

        $input = $this->input('report:generate --password="hunter 2"');
        event(new CommandStarting('report:generate', $input, new NullOutput()));
        event(new CommandFinished('report:generate', $input, new NullOutput(), 1));
    }

    // ── Octane per-request state reset ───────────────────────────────────
    //
    // ContextStore is bound via $this->app->scoped() (see
    // DevPulseServiceProvider::register()) specifically because Octane calls
    // Container::forgetScopedInstances() at its request boundary — the next
    // app(ContextStore::class) resolution then gets a brand new, empty store.
    // These tests call forgetScopedInstances() directly rather than firing a
    // real Octane event, since that's the exact mechanism Octane itself
    // triggers; no Octane package or stub classes needed to exercise it.

    public function test_context_store_resets_breadcrumbs_between_scoped_requests(): void
    {
        $client = $this->bootWith(['devpulse.capture.commands' => false]);

        // First "request": a query adds a breadcrumb, then an exception is
        // reported and should carry it.
        DB::select('select 1');
        $client->expects($this->exactly(2))
            ->method('captureException')
            ->willReturnCallback(function (\Throwable $e, array $extra): bool {
                static $call = 0;
                $call++;

                if ($call === 1) {
                    $this->assertArrayHasKey('breadcrumbs', $extra, 'First request should still see its own breadcrumb.');
                } else {
                    $this->assertArrayNotHasKey(
                        'breadcrumbs',
                        $extra,
                        'Second request must not see breadcrumbs left over from the first — Octane worker reuse would otherwise leak them.'
                    );
                }

                return true;
            });

        $this->report(new \RuntimeException('first request'));

        // Octane boundary: a new request starts on the same (simulated) worker.
        $this->app->forgetScopedInstances();

        // Second "request": no query this time — if state leaked, the
        // exception would still carry the first request's breadcrumb.
        $this->report(new \RuntimeException('second request'));
    }

    // ── Manual instrumentation API ────────────────────────────────────────

    public function test_manual_breadcrumb_is_attached_to_a_later_exception(): void
    {
        $client = $this->bootWith(['devpulse.capture.commands' => false]);
        $client->expects($this->once())
            ->method('captureException')
            ->with(
                $this->anything(),
                $this->callback(function (array $extra): bool {
                    return isset($extra['breadcrumbs'])
                        && collect($extra['breadcrumbs'])->contains(
                            fn ($b) => $b['category'] === 'checkout' && $b['message'] === 'user reached payment step'
                        );
                })
            );

        DevPulse::addBreadcrumb('user reached payment step', 'checkout');
        $this->report(new \RuntimeException('boom'));
    }

    public function test_manual_tag_is_attached_to_a_later_exception(): void
    {
        $client = $this->bootWith(['devpulse.capture.commands' => false]);
        $client->expects($this->once())
            ->method('captureException')
            ->with(
                $this->anything(),
                $this->callback(fn (array $extra) => ($extra['tags']['tenant'] ?? null) === 'acme-co')
            );

        DevPulse::setTag('tenant', 'acme-co');
        $this->report(new \RuntimeException('boom'));
    }

    public function test_manual_context_is_attached_under_custom_context_not_context(): void
    {
        // Deliberately NOT nested under 'context' — devpulse/core's
        // Payload::fromThrowable() always lets its own 'context' key
        // (php/os/sapi/memory) win the array_merge, silently discarding
        // anything else sent under that name. See ContextStore::setContext().
        $client = $this->bootWith(['devpulse.capture.commands' => false]);
        $client->expects($this->once())
            ->method('captureException')
            ->with(
                $this->anything(),
                $this->callback(fn (array $extra) => ($extra['custom_context']['checkout']['cart_id'] ?? null) === 42)
            );

        DevPulse::setContext('checkout', ['cart_id' => 42]);
        $this->report(new \RuntimeException('boom'));
    }

    public function test_manual_context_does_not_leak_across_scoped_requests(): void
    {
        $client = $this->bootWith(['devpulse.capture.commands' => false]);

        DevPulse::setTag('tenant', 'acme-co');

        $client->expects($this->once())
            ->method('captureException')
            ->with(
                $this->anything(),
                $this->callback(fn (array $extra) => !isset($extra['tags']))
            );

        $this->app->forgetScopedInstances();

        $this->report(new \RuntimeException('second request'));
    }
}
