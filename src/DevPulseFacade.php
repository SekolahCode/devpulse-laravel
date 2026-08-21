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

    /**
     * Record a breadcrumb, attached to the next exception/message captured
     * during this request (or Octane-scoped equivalent). Automatic
     * breadcrumbs (queries, logs, Livewire actions) go through the same
     * buffer, so this is safe to call as often as you like.
     */
    public static function addBreadcrumb(string $message, string $category = 'custom', array $data = [], string $level = 'info'): void
    {
        app(ContextStore::class)->addBreadcrumb($message, $category, $data, $level);
    }

    /**
     * Attach a short, indexable tag (e.g. DevPulse::setTag('tenant', 'acme-co'))
     * to every event captured for the rest of this request.
     */
    public static function setTag(string $key, string $value): void
    {
        app(ContextStore::class)->setTag($key, $value);
    }

    /**
     * Attach a named group of structured context
     * (e.g. DevPulse::setContext('checkout', ['cart_id' => 42])) to every
     * event captured for the rest of this request.
     */
    public static function setContext(string $key, array $data): void
    {
        app(ContextStore::class)->setContext($key, $data);
    }
}
