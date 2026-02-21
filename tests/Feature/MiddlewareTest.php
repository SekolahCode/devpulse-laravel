<?php

namespace DevPulse\Laravel\Tests\Feature;

use DevPulse\Client;
use DevPulse\Laravel\Http\Middleware\DevPulseContext;
use DevPulse\Laravel\Tests\TestCase;
use Illuminate\Http\Request;

class MiddlewareTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Bind a PHPUnit mock Client into the container and return it. */
    private function mockClient(): Client
    {
        $client = $this->createMock(Client::class);
        $this->app->instance('devpulse', $client);

        return $client;
    }

    /** Run the middleware against a synthetic GET request and return the response. */
    private function runMiddleware(?Request $request = null): \Symfony\Component\HttpFoundation\Response
    {
        $request ??= Request::create('/');

        return (new DevPulseContext())->handle($request, fn ($r) => response('ok', 200));
    }

    // ── Pass-through behaviour ────────────────────────────────────────────────

    public function test_response_is_passed_through_unchanged(): void
    {
        $this->mockClient();
        config(['devpulse.slow_request_ms' => PHP_INT_MAX]);

        $response = (new DevPulseContext())->handle(
            Request::create('/'),
            fn ($r) => response('hello world', 201)
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('hello world', $response->getContent());
    }

    // ── Fast request ──────────────────────────────────────────────────────────

    public function test_fast_request_does_not_trigger_capture(): void
    {
        $client = $this->mockClient();
        $client->expects($this->never())->method('captureMessage');

        config(['devpulse.slow_request_ms' => PHP_INT_MAX]);

        $this->runMiddleware();
    }

    // ── Slow request ──────────────────────────────────────────────────────────

    public function test_slow_request_triggers_capture_message(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())->method('captureMessage');

        config([
            'devpulse.slow_request_ms'       => 0,  // 0 ms — every request qualifies
            'devpulse.capture.slow_requests'  => true,
        ]);

        $this->runMiddleware();
    }

    public function test_capture_message_contains_method_and_path(): void
    {
        $client = $this->mockClient();
        $client->expects($this->once())
            ->method('captureMessage')
            ->with($this->stringContains('GET'), $this->anything(), $this->anything());

        config(['devpulse.slow_request_ms' => 0, 'devpulse.capture.slow_requests' => true]);

        $this->runMiddleware(Request::create('/api/users', 'GET'));
    }

    // ── Disabled guards ───────────────────────────────────────────────────────

    public function test_capture_is_skipped_when_package_disabled(): void
    {
        $client = $this->mockClient();
        $client->expects($this->never())->method('captureMessage');

        config([
            'devpulse.enabled'         => false,
            'devpulse.slow_request_ms' => 0,
        ]);

        $this->runMiddleware();
    }

    public function test_capture_is_skipped_when_slow_requests_disabled_in_capture_config(): void
    {
        $client = $this->mockClient();
        $client->expects($this->never())->method('captureMessage');

        config([
            'devpulse.slow_request_ms'       => 0,
            'devpulse.capture.slow_requests'  => false,
        ]);

        $this->runMiddleware();
    }

    // ── Threshold boundary ────────────────────────────────────────────────────

    public function test_request_exactly_at_threshold_triggers_capture(): void
    {
        // Set threshold to 0 so any measured duration (>= 0) qualifies
        $client = $this->mockClient();
        $client->expects($this->once())->method('captureMessage');

        config([
            'devpulse.slow_request_ms'       => 0,
            'devpulse.capture.slow_requests'  => true,
        ]);

        $this->runMiddleware();
    }
}
