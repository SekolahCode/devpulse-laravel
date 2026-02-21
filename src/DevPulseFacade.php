<?php

namespace DevPulse\Laravel;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void captureMessage(string $message, string $level = 'info', array $extra = [])
 */
class DevPulseFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'devpulse';
    }

    /**
     * Capture an exception.
     *
     * Aliases Client::captureException() to match the core DevPulse static API,
     * so DevPulse::capture($e) works the same way in Laravel as in plain PHP.
     *
     * @param array<string, mixed> $extra
     */
    public static function capture(\Throwable $e, array $extra = []): void
    {
        $root = static::getFacadeRoot();
        assert($root instanceof \DevPulse\Client);
        $root->captureException($e, $extra);
    }
}
