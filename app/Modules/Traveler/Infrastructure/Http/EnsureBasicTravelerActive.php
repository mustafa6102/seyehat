<?php

namespace App\Modules\Traveler\Infrastructure\Http;

use App\Modules\Traveler\Contracts\BasicTravelerActivation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs before authentication: while closed, the Basic Traveler routes are indistinguishable from absent ones. */
final readonly class EnsureBasicTravelerActive
{
    public function __construct(private BasicTravelerActivation $activation) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->activation->isActive(), 404);

        return $next($request);
    }
}
