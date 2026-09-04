<?php

namespace App\Http\Middleware;

use App\Support\Feature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404 while the unit or an ancestor is off, as OpenPNE 3 answered a disabled plugin's URLs; the
 * routes stay registered, so this middleware is the gate. The answer is the same for a guest and a
 * member, so toggle state never leaks a difference; in an `auth` group a guest meets the login
 * redirect first.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Feature::from($feature)->enabled(), 404);

        return $next($request);
    }
}
