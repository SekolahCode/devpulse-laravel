<?php

namespace DevPulse\Laravel;

/**
 * Per-request store for breadcrumbs, Livewire context, and user-supplied
 * tags/context. Bound as a scoped singleton (see DevPulseServiceProvider),
 * which is the idiomatic Octane-safe pattern: Octane calls
 * Container::forgetScopedInstances() at the request boundary, so the next
 * resolution gets a brand new, empty store automatically — no manual event
 * listening required.
 *
 * Every capture listener MUST resolve this fresh via app(ContextStore::class)
 * at the moment it fires, rather than capturing one instance by reference at
 * boot() time — otherwise a long-lived Octane worker would keep using the
 * first request's (stale) instance forever.
 */
class ContextStore
{
    /** @var array<int, array<string, mixed>> */
    private array $breadcrumbs = [];

    /** @var array<string, mixed> */
    private array $livewireContext = [];

    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, array<string, mixed>> */
    private array $context = [];

    public function __construct(private readonly int $maxBreadcrumbs = 20)
    {
    }

    /**
     * Record a breadcrumb. Auto-generated breadcrumbs (queries, logs,
     * Livewire actions) go through this same method so the ring-buffer
     * trimming behaves identically for manual and automatic entries.
     *
     * @param array<string, mixed> $data
     */
    public function addBreadcrumb(string $message, string $category = 'custom', array $data = [], string $level = 'info'): void
    {
        if (count($this->breadcrumbs) >= $this->maxBreadcrumbs) {
            array_shift($this->breadcrumbs);
        }

        $this->breadcrumbs[] = array_filter([
            'type'      => $category === 'custom' ? 'custom' : $category,
            'timestamp' => now()->toISOString(),
            'category'  => $category,
            'message'   => $message,
            'data'      => $data,
            'level'     => $level,
        ], static fn ($v) => $v !== [] && $v !== null);
    }

    /** @return array<int, array<string, mixed>> */
    public function breadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    /**
     * Attach a user-defined tag — a short, indexable key/value pair
     * (e.g. "tenant" => "acme-co"). For structured data, use setContext()
     * instead.
     */
    public function setTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    /** @return array<string, string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * Attach a named group of structured, arbitrary context
     * (e.g. setContext('checkout', ['cart_id' => 42, 'step' => 'payment'])).
     *
     * Deliberately NOT called "context" as a top-level payload key — that
     * key is already owned by devpulse/core's Payload::buildContext()
     * (php/os/sapi/memory) and array_merge() there always lets the
     * library-controlled value win, so anything sent under 'context' would
     * be silently discarded. This ships under 'custom_context' instead.
     *
     * @param array<string, mixed> $data
     */
    public function setContext(string $key, array $data): void
    {
        $this->context[$key] = $data;
    }

    /** @return array<string, array<string, mixed>> */
    public function context(): array
    {
        return $this->context;
    }

    public function setLivewire(string $key, mixed $value): void
    {
        $this->livewireContext[$key] = $value;
    }

    public function clearLivewireKey(string $key): void
    {
        unset($this->livewireContext[$key]);
    }

    /** @return array<string, mixed> */
    public function livewire(): array
    {
        return $this->livewireContext;
    }

    /**
     * Merge everything this store holds into an outgoing event's extra
     * payload. Called from every register*Capture() listener right before
     * app('devpulse')->captureException()/captureMessage().
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function applyTo(array $extra): array
    {
        if (!empty($this->breadcrumbs)) {
            $extra['breadcrumbs'] = $this->breadcrumbs;
        }

        if (!empty($this->livewireContext)) {
            $extra['livewire'] = $this->livewireContext;
        }

        if (!empty($this->tags)) {
            $extra['tags'] = $this->tags;
        }

        if (!empty($this->context)) {
            $extra['custom_context'] = $this->context;
        }

        return $extra;
    }
}
