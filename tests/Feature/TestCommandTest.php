<?php

namespace DevPulse\Laravel\Tests\Feature;

use DevPulse\Client;
use DevPulse\Laravel\Tests\TestCase;

class TestCommandTest extends TestCase
{
    public function test_sends_a_test_message_through_the_configured_client(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('captureMessage')
            ->with(
                $this->stringContains('DevPulse test event'),
                'info',
                $this->callback(fn (array $extra) => $extra['source'] === 'devpulse:test')
            )
            ->willReturn(true);

        $this->app->instance('devpulse', $client);

        $this->artisan('devpulse:test')->assertSuccessful();
    }

    public function test_fails_cleanly_when_dsn_is_missing(): void
    {
        config(['devpulse.dsn' => '']);

        $this->artisan('devpulse:test')->assertFailed();
    }

    public function test_reports_failure_when_the_client_could_not_send(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('captureMessage')->willReturn(false);
        $this->app->instance('devpulse', $client);

        $this->artisan('devpulse:test')->assertFailed();
    }
}
