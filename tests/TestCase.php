<?php

namespace DevPulse\Laravel\Tests;

use DevPulse\Laravel\DevPulseServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DevPulseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Use a valid-looking DSN so the Client constructor doesn't throw.
        // The transport will never actually send because we mock the client in tests
        // that exercise network-level behaviour.
        $app['config']->set('devpulse.dsn', 'https://devpulse.test/api/v1/ingest');
        $app['config']->set('devpulse.enabled', true);
        $app['config']->set('devpulse.async', false);
    }
}
