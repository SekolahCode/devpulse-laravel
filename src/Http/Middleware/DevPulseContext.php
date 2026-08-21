<?php

namespace DevPulse\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DevPulseContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $enabled = config('devpulse.enabled', true)
            && config('devpulse.capture.slow_requests', true)
            && !empty(config('devpulse.dsn'));

        if (!$enabled) {
            return $response;
        }

        if ($this->isIgnored($request)) {
            return $response;
        }

        $ms        = (microtime(true) - $start) * 1000;
        $threshold = (int) config('devpulse.slow_request_ms', 3000);

        if ($ms < $threshold) {
            return $response;
        }

        $extra = [
            'platform'    => 'laravel',
            'environment' => config('devpulse.environment', app()->environment()),
            'php'         => PHP_VERSION,
            'laravel'     => app()->version(),
            'route'       => optional($request->route())->getName() ?? $request->path(), // @phpstan-ignore-line
            'method'      => $request->method(),
            'url'         => $request->fullUrl(),
            'ip'          => $request->ip(),
            'status_code' => $response->getStatusCode(),
            'duration_ms' => round($ms, 2),
            'threshold_ms'=> $threshold,
        ];

        // Attach user if available
        if (config('devpulse.user_context', true)) {
            try {
                /** @phpstan-ignore-next-line */
                $user = auth()->user();
                if ($user) {
                    $extra['user'] = array_filter([
                        'id'    => $user->getAuthIdentifier(),
                        'email' => $user->email ?? null,
                        'name'  => $user->name  ?? null,
                    ]);
                }
            } catch (Throwable) {
                // auth not available
            }
        }

        app('devpulse')->captureMessage(
            sprintf('Slow request: %s %s (%.0fms)', $request->method(), $request->path(), $ms),
            'warning',
            $extra
        );

        return $response;
    }

    /**
     * Check the request's route name and path against
     * devpulse.slow_request_ignore (Str::is() wildcards), so health checks
     * and monitoring endpoints hit on a tight interval don't get flagged.
     */
    private function isIgnored(Request $request): bool
    {
        $patterns = config('devpulse.slow_request_ignore', []);

        if (empty($patterns)) {
            return false;
        }

        $routeName = optional($request->route())->getName(); // @phpstan-ignore-line
        $path      = $request->path();

        foreach ($patterns as $pattern) {
            if (($routeName && \Illuminate\Support\Str::is($pattern, $routeName)) || \Illuminate\Support\Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
