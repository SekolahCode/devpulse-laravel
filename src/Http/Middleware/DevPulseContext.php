<?php

namespace DevPulse\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DevPulseContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('devpulse.enabled') || !config('devpulse.capture.slow_requests')) {
            return $next($request);
        }

        $start    = microtime(true);
        $response = $next($request);
        $duration = (int) round((microtime(true) - $start) * 1000);

        $threshold = config('devpulse.slow_request_ms', 3000);
        if ($duration >= $threshold) {
            app('devpulse')->captureMessage(
                "Slow request ({$duration}ms): {$request->method()} {$request->path()}",
                'warning',
                [
                    'context' => [
                        'duration_ms' => $duration,
                        'route'       => $request->route()?->getName(),
                        'status'      => $response->getStatusCode(),
                    ],
                ]
            );
        }

        return $response;
    }
}
