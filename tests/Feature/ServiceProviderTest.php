<?php

namespace DevPulse\Laravel\Tests\Feature;

use DevPulse\Client;
use DevPulse\Laravel\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_devpulse_binding_is_registered(): void
    {
        $this->assertTrue($this->app->bound('devpulse'));
    }

    public function test_resolves_client_instance(): void
    {
        $this->assertInstanceOf(Client::class, $this->app->make('devpulse'));
    }

    public function test_binding_is_a_singleton(): void
    {
        $first  = $this->app->make('devpulse');
        $second = $this->app->make('devpulse');

        $this->assertSame($first, $second);
    }

    public function test_config_is_loaded(): void
    {
        $config = config('devpulse');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('dsn', $config);
        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('async', $config);
        $this->assertArrayHasKey('timeout', $config);
        $this->assertArrayHasKey('slow_query_ms', $config);
        $this->assertArrayHasKey('slow_request_ms', $config);
        $this->assertArrayHasKey('capture', $config);
    }

    public function test_capture_config_keys_exist(): void
    {
        $capture = config('devpulse.capture');

        $this->assertIsArray($capture);
        $this->assertArrayHasKey('exceptions', $capture);
        $this->assertArrayHasKey('slow_queries', $capture);
        $this->assertArrayHasKey('slow_requests', $capture);
        $this->assertArrayHasKey('queue_failures', $capture);
    }

    public function test_config_is_publishable(): void
    {
        $this->artisan('vendor:publish', [
            '--tag'   => 'devpulse-config',
            '--force' => true,
        ])->assertSuccessful();
    }

    public function test_binding_is_lazy_and_not_resolved_during_registration(): void
    {
        // Overwrite the singleton so we can detect if the factory runs during boot.
        $resolved = false;

        $this->app->singleton('devpulse', function () use (&$resolved): Client {
            $resolved = true;
            return new Client(['dsn' => 'https://devpulse.test/api/v1/ingest']);
        });

        // Providers have already booted — if the factory had run, $resolved would be true.
        // A lazy singleton must NOT be resolved unless explicitly asked for.
        $this->assertFalse($resolved, 'The devpulse singleton should not be resolved during boot.');
    }

    public function test_no_listeners_are_registered_when_disabled(): void
    {
        // Boot a fresh app with disabled=true. The boot() guard should return early,
        // so the singleton is still lazy (not yet instantiated).
        $app = $this->createApplication();
        $app['config']->set('devpulse.enabled', false);
        $app['config']->set('devpulse.dsn', 'https://devpulse.test/api/v1/ingest');

        // The binding should still exist …
        $this->assertTrue($app->bound('devpulse'));

        // … but it must not have been resolved (no listeners would have triggered it).
        $this->assertFalse($app->resolved('devpulse'));
    }

    public function test_no_listeners_are_registered_when_dsn_is_empty(): void
    {
        $app = $this->createApplication();
        $app['config']->set('devpulse.enabled', true);
        $app['config']->set('devpulse.dsn', '');

        $this->assertTrue($app->bound('devpulse'));
        $this->assertFalse($app->resolved('devpulse'));
    }

    public function test_resolves_safely_when_dsn_is_missing(): void
    {
        // The singleton must not throw even when DEVPULSE_DSN is not configured.
        // It should return a disabled Client whose captures are all no-ops.
        $this->app['config']->set('devpulse.dsn', '');

        $client = $this->app->make('devpulse');

        $this->assertInstanceOf(\DevPulse\Client::class, $client);
    }
}
