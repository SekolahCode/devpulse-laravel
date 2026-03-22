<?php

namespace DevPulse\Laravel;

use DevPulse\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool captureMessage(string $message, string $level = 'info', array $extra = [])
 *
 * @see Client
 */
class DevPulseFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'devpulse';
    }

    /** @param array<string, mixed> $extra */
    public static function capture(\Throwable $e, array $extra = []): bool
    {
        return static::getFacadeRoot()->captureException($e, $extra);
    }

    /**
     * Replace the bound client with a FakeClient for testing.
     * Records all captured events so you can assert on them.
     *
     * Usage in tests:
     *   $fake = DevPulse::fake();
     *   // ... trigger something ...
     *   $fake->assertCaptured(\RuntimeException::class);
     */
    public static function fake(): FakeClient
    {
        $fake = new FakeClient();
        static::swap($fake);
        return $fake;
    }
}
