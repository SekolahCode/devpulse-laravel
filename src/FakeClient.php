<?php

namespace DevPulse\Laravel;

use Throwable;
use PHPUnit\Framework\Assert;

/**
 * A test double for the DevPulse client.
 * Swap it in with DevPulse::fake() — it records all captures
 * so you can make assertions in your tests.
 */
class FakeClient
{
    /** @var array<int, array{type: string, payload: mixed}> */
    private array $captured = [];

    public function captureException(Throwable $e, array $extra = []): void
    {
        $this->captured[] = ['type' => 'exception', 'payload' => $e, 'extra' => $extra];
    }

    public function captureMessage(string $message, string $level = 'info', array $extra = []): void
    {
        $this->captured[] = ['type' => 'message', 'payload' => $message, 'level' => $level, 'extra' => $extra];
    }

    /** Assert that an exception of the given class was captured. */
    public function assertCaptured(string $exceptionClass): void
    {
        $found = collect($this->captured)
            ->where('type', 'exception')
            ->first(fn ($c) => $c['payload'] instanceof $exceptionClass);

        Assert::assertNotNull(
            $found,
            "Failed asserting that [{$exceptionClass}] was captured by DevPulse."
        );
    }

    /** Assert that a message matching the given string/pattern was captured. */
    public function assertCapturedMessage(string $contains): void
    {
        $found = collect($this->captured)
            ->where('type', 'message')
            ->first(fn ($c) => str_contains($c['payload'], $contains));

        Assert::assertNotNull(
            $found,
            "Failed asserting that a message containing [{$contains}] was captured by DevPulse."
        );
    }

    /** Assert nothing was captured. */
    public function assertNothingCaptured(): void
    {
        Assert::assertEmpty(
            $this->captured,
            'Failed asserting that nothing was captured by DevPulse. Captured: ' . count($this->captured) . ' event(s).'
        );
    }

    /** Return all captured events (for custom assertions). */
    public function all(): array
    {
        return $this->captured;
    }

    public function count(): int
    {
        return count($this->captured);
    }
}
