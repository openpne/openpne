<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AdminRealm;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global, so it answers ahead of every gate on either stack, whoever sends the visit: an Inertia visit,
 * as the Modern login and registration make to url.intended, can swap in neither a panel page nor a refusal.
 */
final class LoadAdminRealmInFull
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasHeader('X-Inertia') && AdminRealm::matches($request)) {
            return Inertia::location($request->fullUrl());
        }

        return $next($request);
    }
}
