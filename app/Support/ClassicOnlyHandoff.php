<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Under classic_only an Inertia navigation can only come from a stale Modern tab: a 409 location
 * reloads it into Classic. Answered from HandleInertiaRequests and, for a route middleware's abort
 * that never reaches it, from the error renderer (docs/internals/feature-modules.md, "Classic only").
 */
final class ClassicOnlyHandoff
{
    /** The handoff for a GET; null when the request is not one to hand off — a non-GET runs, and its redirect is handed off instead. */
    public static function for(Request $request): ?Response
    {
        if (! $request->isMethod('GET') || ! $request->hasHeader('X-Inertia') || SurfaceResolver::modernAvailable()) {
            return null;
        }

        $response = Inertia::location($request->fullUrl());
        $response->headers->set('Vary', 'X-Inertia');

        return $response;
    }
}
