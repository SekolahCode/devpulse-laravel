<?php

namespace DevPulse\Laravel\Tests\Unit;

use DevPulse\Client;
use DevPulse\Laravel\DevPulseFacade;
use DevPulse\Laravel\Tests\TestCase;

class FacadeTest extends TestCase
{
    public function test_facade_resolves_to_client_instance(): void
    {
        $this->assertInstanceOf(Client::class, DevPulseFacade::getFacadeRoot());
    }

    public function test_facade_uses_devpulse_container_key(): void
    {
        $reflection = new \ReflectionMethod(DevPulseFacade::class, 'getFacadeAccessor');
        $reflection->setAccessible(true);

        $this->assertSame('devpulse', $reflection->invoke(null));
    }

    public function test_facade_root_is_the_same_singleton_as_the_container(): void
    {
        $fromContainer = $this->app->make('devpulse');
        $fromFacade    = DevPulseFacade::getFacadeRoot();

        $this->assertSame($fromContainer, $fromFacade);
    }

    public function test_capture_delegates_to_captureException_on_the_client(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('captureException')
            ->with($this->isInstanceOf(\RuntimeException::class), []);

        $this->app->instance('devpulse', $client);
        DevPulseFacade::clearResolvedInstance('devpulse');

        DevPulseFacade::capture(new \RuntimeException('test'));
    }
}
