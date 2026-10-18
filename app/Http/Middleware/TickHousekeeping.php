<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Housekeeping;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the cleanup in terminate(), after the response, and directly: a deferred callback queued
 * here would never run, since the deferred invoker's own terminate() has already passed.
 */
final class TickHousekeeping
{
    public function __construct(private readonly Housekeeping $housekeeping) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Any authenticated request, whichever guard answered; a guest's would pay the cache read for nothing.
        if ($request->user() === null) {
            return;
        }

        if (! Cache::add('housekeeping:'.now()->toDateString(), true, now()->addDay())) {
            return;
        }

        rescue(fn () => $this->housekeeping->run());
    }
}
