<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireProductionDiagnosticsAccess
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'production-diagnostics:'.hash('sha256', (string) $request->ip());
        $limited = $this->limiter->tooManyAttempts($key, 10);
        $this->limiter->hit($key, 60);

        $expected = config('diagnostics.token');
        $provided = $request->header('X-Diagnostics-Token');
        if (! config('diagnostics.enabled') || ! is_string($expected) || $expected === ''
            || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(404);
        }
        abort_if($limited, 429);

        return $next($request);
    }
}
